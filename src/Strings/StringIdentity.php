<?php
/**
 * A string's identity and a map row's key, without any string service.
 *
 * @package PerfLocale
 */

declare(strict_types=1);

namespace PerfLocale\Strings;

defined( 'ABSPATH' ) || exit;

/**
 * Answers a caller needs without loading StringTranslation or MapBuilder (the e-mail path). Those keep their own copies:
 * gettext's per-string path and "a miss loads MapBuilder alone" (CheckoutLoadSetTest holds them equal).
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class StringIdentity {

	/**
	 * The tuple's identity form (StringTranslation::identity()).
	 *
	 * @param string $domain  Text domain.
	 * @param string $context Context.
	 * @param string $text    Original text.
	 * @param bool   $other   The other form.
	 * @return string
	 */
	public static function identity( string $domain, string $context, string $text, bool $other = false ): string {
		return $domain . '|' . $context . ( str_contains( $context, '|' ) !== $other ? "\x04" : '|' ) . $text;
	}

	/**
	 * The SHA-256 of identity(): the strings table's original_hash.
	 *
	 * @param string $domain  Text domain.
	 * @param string $context Context.
	 * @param string $text    Original text.
	 * @param bool   $other   The other form.
	 * @return non-falsy-string 64-character hex hash.
	 */
	public static function identity_hash( string $domain, string $context, string $text, bool $other = false ): string {
		return hash( 'sha256', self::identity( $domain, $context, $text, $other ) );
	}

	/**
	 * 1 while the identity switch-over runs (state option not `done`), else 0.
	 *
	 * @return int
	 */
	public static function bridging(): int {
		$state = json_decode( self::text( get_option( 'perflocale_str_ident', '' ) ), true );

		return is_array( $state ) && 'done' === ( $state['phase'] ?? '' ) && empty( $state['held_n'] ) ? 0 : 1;
	}

	/**
	 * A map row's key: while bridging (b_text set), a row stored under its other-form hash is keyed by its identity hash; else its stored hash.
	 *
	 * @param \stdClass $row Map row (original_hash, domain, b_ctx, b_text).
	 * @return string
	 */
	public static function row_key( \stdClass $row ): string {
		$stored = self::text( $row->original_hash ?? null );

		if ( ! isset( $row->b_text ) ) {
			return $stored;
		}

		[ $domain, $context, $text ] = [ self::text( $row->domain ?? null ), self::text( $row->b_ctx ?? null ), self::text( $row->b_text ) ];

		return self::identity_hash( $domain, $context, $text, true ) === $stored ? self::identity_hash( $domain, $context, $text ) : $stored;
	}

	/**
	 * A scalar as a string; anything else ''.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
