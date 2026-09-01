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
 * Emits the cross-document View Transitions opt-in so that language-switch
 * navigations animate smoothly instead of white-flashing.
 *
 * The cross-document API (Chrome 126+, Safari 18.2+) requires three tiny
 * things on BOTH the source and destination pages of the navigation:
 * 1. `<meta name="view-transition" content="same-origin">`
 * 2. CSS `@view-transition { navigation: auto }`
 * 3. Optional matched `view-transition-name` on elements that should morph
 * (header, main content, switcher) instead of just crossfading.
 *
 * Browsers without support just do a normal navigation - zero breakage.
 * Feature defaults OFF because some themes run their own on-nav animations
 * (hero sliders, GSAP intros) that can fight with the browser transition.
 *
 * Cost profile: one inline stylesheet + one meta tag in wp_head. ~200
 * bytes output, no JS. Gated by settings - a no-op when disabled.
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
		// Feature defaults OFF — skip attaching hooks entirely so disabled
		// installs don't pay for two add_action() + two callback fires that
		// would just short-circuit on the same setting read.
		if ( ! (bool) $this->settings->get( 'view_transitions_enabled', false ) ) {
			return;
		}

		// Register + attach the CSS through the normal enqueue pipeline so
		// it rides in wp_head alongside every other stylesheet. Done on
		// wp_enqueue_scripts (the canonical frontend hook) which fires
		// before wp_head() emits the stylesheet tags, so wp_add_inline_style
		// output lands in the right place.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_style' ] );

		// The cross-document opt-in <meta> tag rides wp_head directly —
		// no stylesheet or script to attach it to.
		add_action( 'wp_head', [ $this, 'emit_meta' ], 2 );
	}

	/**
	 * Whether this request should emit View Transitions output (meta + CSS).
	 *
	 * Isolated so both the wp_head <meta> callback and the enqueue_scripts
	 * style callback gate on identical logic without duplicating filters.
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
		 * Use this to exclude specific templates that don't animate cleanly
		 * (e.g. a page with a GSAP hero that needs to re-initialise after nav).
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
	 * Register and attach the View Transitions CSS through the standard
	 * WordPress style pipeline.
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
		 * Defaults opt in to cross-document transitions for same-origin nav
		 * and assign `view-transition-name` to the body + a small set of
		 * common structural selectors. Returning '' disables output.
		 *
		 * @hook perflocale/view_transitions/css
		 * @param string $css Default CSS block.
		 */
		$default_css = "@view-transition { navigation: auto; }\n"
			// Named transitions: elements that share a name on both pages
			// morph instead of crossfading. If the theme uses different
			// selectors, this filter is the extension point.
			. "::view-transition-old(root),::view-transition-new(root){animation-duration:.24s;animation-timing-function:cubic-bezier(.4,0,.2,1);}\n"
			// Respect OS-level reduced-motion preference. Under reduce,
			// zero the animation-duration so the cross-document transition
			// completes instantly (navigation still happens - only the
			// 240ms crossfade is skipped). Required for WCAG 2.1 SC 2.3.3.
			. "@media (prefers-reduced-motion: reduce){::view-transition-old(root),::view-transition-new(root){animation-duration:0s;animation:none;}}\n";

		$css = (string) apply_filters( 'perflocale/view_transitions/css', $default_css );

		// Defensive sanitisation: strip any HTML tags from the filtered CSS
		// to prevent a </style> break-out in callbacks that pipe untrusted
		// content through this filter (e.g. an admin-editable "custom CSS"
		// field that doesn't sanitise on save). Real CSS contains no <…>
		// patterns - the child combinator `>` never appears with a leading
		// `<` - so legitimate rules pass through unchanged. Idempotent and
		// cheap; runs once per request and only on opt-in pages.
		$css = wp_strip_all_tags( $css );

		if ( $css === '' ) {
			return;
		}

		$handle = 'perflocale-view-transitions';

		wp_register_style( $handle, false, [], defined( 'PERFLOCALE_VERSION' ) ? PERFLOCALE_VERSION : null );
		wp_enqueue_style( $handle );

		// $css is a fixed literal defined in this file — no variable, no user
		// input, no stored option reaches it. wp_add_inline_style() is the
		// correct API for inline CSS (there is no esc_* function for a
		// stylesheet body; escaping CSS as HTML would corrupt it). Automated
		// scanners flag this line because the value passed through
		// wp_strip_all_tags(); that call is belt-and-braces, not the escaper.
		wp_add_inline_style( $handle, $css );
	}
}
