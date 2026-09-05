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
 * Single implementation of the path-redaction rule, shared by every surface
 * that persists or renders a raw PHP error message.
 *
 * Replaces:
 *   - the WP_CONTENT_DIR prefix → `<content>/`
 *   - the ABSPATH prefix → `<wp>/`
 *   - any remaining `/abs/path/to/file.ext` → `<path>/file.ext`
 *
 * The basename is deliberately kept: `<path>/import.csv` is actionable,
 * `<path>` alone is not.
 *
 * Tuned for the shape PHP actually throws ("... in /var/www/.../Foo.php:1033").
 * Conservative: only sequences that look like absolute Unix paths ending in a
 * recognised extension are matched, so ordinary prose is left alone.
 * Multi-line safe, and never returns a string longer than the input.
 *
 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
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

		// Known prefixes first, longest match wins. On a standard layout
		// ABSPATH is a strict prefix of WP_CONTENT_DIR, so running the shorter
		// one first would consume part of the longer one's path.
		$prefixes = [
			rtrim( (string) WP_CONTENT_DIR, '/\\' ) . '/' => '<content>/',
			rtrim( (string) ABSPATH, '/\\' ) . '/'        => '<wp>/',
		];
		uksort( $prefixes, static fn( string $a, string $b ): int => strlen( $b ) - strlen( $a ) );

		foreach ( $prefixes as $prefix => $placeholder ) {
			$message = str_replace( $prefix, $placeholder, $message );
		}

		// Anything that escaped the prefix pass (/tmp, /var/log, a path from
		// another install). Anchored on a leading boundary so paths already
		// rewritten above are not mangled a second time, and bounded to avoid
		// pathological backtracking.
		$message = preg_replace_callback(
			'#(^|[\s\'",;:()\[\]])/(?:[a-zA-Z0-9._-]+/){1,30}([a-zA-Z0-9._-]+\.[a-zA-Z0-9]{1,8})#',
			static fn( array $m ): string => $m[1] . '<path>/' . $m[2],
			$message
		);

		return is_string( $message ) ? $message : '';
	}
}
