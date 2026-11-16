<?php
/**
 * PerfLocale Contact Form 7 addon: the form editor screen.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-only code of the CF7 add-on, loaded when CF7 loads one of its own screens.
 */
final class PerfLocaleContactForm7Screen {

	/**
	 * Add the panel stylesheet callback on CF7's form page (form list and editor).
	 *
	 * @param mixed $page CF7 admin page slug (the `page` query argument).
	 * @return void
	 */
	public static function on_load( mixed $page ): void {
		if ( 'wpcf7' === $page ) {
			add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_panel_styles' ] );
		}
	}

	/**
	 * Load the panel's stylesheet when CF7 edits a saved form, the only case its editor prints the panel.
	 *
	 * Reuses the metabox handle, so Helper's `perflocale*` pass adds the RTL twin.
	 *
	 * @param mixed $hook_suffix Current admin page's hook suffix (mixed: iframe_header() passes null outside admin.php).
	 * @return void
	 */
	public static function enqueue_panel_styles( mixed $hook_suffix ): void {
		// CF7's own choice, not the `post` argument: it loads the form before this hook; none on the list, unsaved on Add New.
		$form = function_exists( 'wpcf7_get_current_contact_form' ) ? wpcf7_get_current_contact_form() : null;

		if ( ! is_string( $hook_suffix ) || ! $form instanceof \WPCF7_ContactForm || $form->initial() ) {
			return;
		}

		wp_enqueue_style(
			'perflocale-metabox',
			PERFLOCALE_URL . 'assets/css/metabox.css',
			[],
			PERFLOCALE_VERSION
		);
	}
}
