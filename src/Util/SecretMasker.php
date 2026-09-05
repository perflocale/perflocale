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
 * Single implementation of the credential-redaction rule, shared by every
 * surface that persists or renders third-party error text.
 *
 * The rule matches the SAFE shape rather than the unsafe one: a 24+ character
 * run of `[A-Za-z0-9_-]` survives only when it looks like a lowercase
 * snake_case identifier (letter-initial, `word_word_word`, each segment at
 * most 16 characters) — which is what this plugin's hook, filter and
 * capability names look like. Everything else is redacted, so hex digests,
 * UUIDs, `sk-…` keys and all-letter base62 tokens fail closed even when they
 * contain no digit.
 *
 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
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
