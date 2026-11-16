<?php
/**
 * The unverified string count with Scan now (admin notice and Site Health).
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loaded only on PerfLocale screens while the identity state counts unverified rows, and by Site Health.
 *
 * @internal
 */
final class StrIdentNotice {

	/**
	 * The count sentence; one msgid for the notice and Site Health.
	 *
	 * @param int $count Unverified rows.
	 * @return string Plain text.
	 */
	public static function sentence( int $count ): string {
		/* translators: %d: number of strings. */
		return sprintf( _n( '%d stored string has text that differs from its identity. In database mode it is not translated until it is stored again, for example by a string scan or an import.', '%d stored strings have text that differs from their identity. In database mode they are not translated until they are stored again, for example by a string scan or an import.', $count, 'perflocale' ), $count );
	}

	/**
	 * Scan now: a POST to the Strings screen's own scan handler (AdminForms::process_string_scan, nonce, manage_options).
	 *
	 * @return string HTML.
	 */
	public static function scan_form(): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=perflocale-strings' ) ) . '">'
			. wp_nonce_field( 'perflocale_scan_strings', '_wpnonce', true, false )
			. '<input type="hidden" name="perflocale_scan" value="1"><p><button type="submit" class="button">' . esc_html__( 'Scan now', 'perflocale' ) . '</button></p></form>';
	}

	/**
	 * Render the notice (admin_notices) to a manage_options user while the state counts unverified rows.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state = json_decode( \PerfLocale\Util\Scalar::str( get_option( 'perflocale_str_ident', '' ) ), true );
		$count = is_array( $state ) ? \PerfLocale\Util\Scalar::int( $state['unverified_n'] ?? 0 ) : 0;

		if ( $count < 1 ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- scan_form() escapes its parts.
		echo '<div class="notice notice-warning"><p><strong>PerfLocale</strong>: ' . esc_html( self::sentence( $count ) ) . '</p>' . self::scan_form() . '</div>';
	}
}
