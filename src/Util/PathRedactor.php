<?php
/**
 * Strip absolute filesystem paths from text that will be stored or displayed.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one path-redaction rule for stored or shown PHP error text.
 *
 * WP_CONTENT_DIR becomes `<content>/`, ABSPATH `<wp>/`, any other absolute path ending in a file extension
 * `<path>/<file>` (the file name is actionable). Names may hold single spaces: a symlinked plugin's real path does.
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 */
final class PathRedactor {

	/**
	 * Redact absolute paths.
	 *
	 * @param string $message Raw exception/error message.
	 * @return string
	 */
	public static function redact( string $message ): string {
		if ( $message === '' ) {
			return $message;
		}

		// Longest prefix first: ABSPATH is usually a prefix of WP_CONTENT_DIR.
		$prefixes = [
			rtrim( (string) WP_CONTENT_DIR, '/\\' ) . '/' => '<content>/',
			rtrim( (string) ABSPATH, '/\\' ) . '/'        => '<wp>/',
		];
		uksort( $prefixes, static fn( string $a, string $b ): int => strlen( $b ) - strlen( $a ) );

		foreach ( $prefixes as $prefix => $placeholder ) {
			$message = str_replace( $prefix, $placeholder, $message );
		}

		// Paths outside both prefixes. The leading boundary skips paths rewritten above; {1,30} bounds backtracking.
		$message = preg_replace_callback(
			'#(^|[\s\'",;:()\[\]])/(?:[a-zA-Z0-9._-]+(?: [a-zA-Z0-9._-]+)*/){1,30}([a-zA-Z0-9._-]+(?: [a-zA-Z0-9._-]+)*\.[a-zA-Z0-9]{1,8})#',
			static fn( array $m ): string => $m[1] . '<path>/' . $m[2],
			$message
		);

		return is_string( $message ) ? $message : '';
	}
}
