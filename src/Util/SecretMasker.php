<?php
/**
 * Redact credential-shaped runs from text that will be stored or displayed.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one credential-redaction rule for stored or shown third-party error text.
 *
 * It allowlists the safe shape: a 24+ character `[A-Za-z0-9_-]` run survives only as lowercase snake_case
 * (segments of at most 16), like the plugin's hook names; hex, UUIDs, `sk-` keys and all-letter tokens fail closed.
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 */
final class SecretMasker {

	/**
	 * Replace credential-shaped runs with `[REDACTED]`.
	 *
	 * @param string $text Text to sanitise. Returned unchanged when empty.
	 * @return string
	 */
	public static function mask( string $text ): string {
		if ( $text === '' ) {
			return $text;
		}

		$masked = preg_replace_callback(
			'/[A-Za-z0-9_\-]{24,}/',
			static function ( array $matches ): string {
				return 1 === preg_match( '/^[a-z][a-z0-9]{0,15}(?:_[a-z0-9]{1,16})+$/', $matches[0] )
					? $matches[0]
					: '[REDACTED]';
			},
			$text
		);

		return is_string( $masked ) ? $masked : $text;
	}
}
