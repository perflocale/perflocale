<?php
/**
 * The outcome notice of an Export & Import form, carried to the tab it redirects to.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries an Export & Import handler's notice to the tab it redirects to.
 *
 * The redirect holds only a random token for a per-user transient read once, so a crafted link shows nothing.
 */
final class ImportNotice {

	/**
	 * The redirect's query argument.
	 */
	public const ARG = 'perflocale_import_notice';

	/**
	 * How long a record waits for its screen.
	 */
	private const TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Notice types: success, needs attention, error.
	 */
	private const TYPES = [ 'success', 'warning', 'error' ];

	/**
	 * Transient name of a record.
	 *
	 * @param int    $user_id User.
	 * @param string $token   Record token.
	 * @return string
	 */
	private static function key( int $user_id, string $token ): string {
		return 'perflocale_import_notice_' . $user_id . '_' . $token;
	}

	/**
	 * The redirect target with a record of the notice for the current user.
	 * Without a user, a known type or a message, the target unchanged.
	 *
	 * @param string $url     Redirect target.
	 * @param string $type    success, warning or error.
	 * @param string $message Notice text.
	 * @return string
	 */
	public static function url( string $url, string $type, string $message ): string {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 || '' === trim( $message ) || ! in_array( $type, self::TYPES, true ) ) {
			return $url;
		}

		$token = bin2hex( random_bytes( 16 ) );

		set_transient(
			self::key( $user_id, $token ),
			[
				'type'    => $type,
				'message' => $message,
			],
			self::TTL
		);

		return add_query_arg( self::ARG, $token, $url );
	}

	/**
	 * The current user's record named by the request's token, once: the
	 * record is deleted. The message comes back as plain one-line text.
	 *
	 * @return array{type: string, message: string}|null
	 */
	public static function take(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: the record is the current user's own.
		$token   = isset( $_GET[ self::ARG ] ) && is_string( $_GET[ self::ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::ARG ] ) ) : '';
		$user_id = get_current_user_id();

		if ( $user_id <= 0 || 1 !== preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			return null;
		}

		$key    = self::key( $user_id, $token );
		$stored = get_transient( $key );

		if ( false === $stored ) {
			return null;
		}

		delete_transient( $key );

		$type    = is_array( $stored ) ? ( $stored['type'] ?? '' ) : '';
		$message = is_array( $stored ) ? ( $stored['message'] ?? '' ) : '';
		// One line of plain text, printed through esc_html(): text such as "<file>" stays as written.
		$message = is_string( $message ) ? trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $message ) ) : '';

		if ( ! is_string( $type ) || ! in_array( $type, self::TYPES, true ) || '' === $message ) {
			return null;
		}

		return [
			'type'    => $type,
			'message' => $message,
		];
	}
}
