<?php
/**
 * IP address check.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper::is_public_ipv4(), loaded only by GeoRedirect and the outbound URL guard.
 */
final class IpAddress {

	/**
	 * True when `$ip` (IPv4 or IPv6) is public, as filter_var's NO_PRIV_RANGE | NO_RES_RANGE judges it.
	 *
	 * Do not add blocks: GeoRedirect uses it for visitors; outbound guards use is_public_outbound_address().
	 * Rejecting dotted-quad IPv6 reproduces PHP's quirk: dropping it loosens an SSRF guard.
	 *
	 * @param string $ip Candidate address.
	 */
	public static function is_public_ipv4( string $ip ): bool {
		if ( ! function_exists( 'inet_pton' ) || $ip === '' || strpos( $ip, "\0" ) !== false ) {
			return false;
		}

		$bin = inet_pton( $ip );

		if ( $bin === false ) {
			return false;
		}

		if ( strlen( $bin ) === 4 ) {
			$a = ord( $bin[0] );
			$b = ord( $bin[1] );

			// Unspecified, private class A, and loopback blocks.
			if ( $a === 0 || $a === 10 || $a === 127 ) {
				return false;
			}

			// Link-local, which is where the cloud metadata address lives.
			if ( $a === 169 && $b === 254 ) {
				return false;
			}

			// The private class B block.
			if ( $a === 172 && ( $b & 0xF0 ) === 16 ) {
				return false;
			}

			// The private class C block.
			if ( $a === 192 && $b === 168 ) {
				return false;
			}

			// Reserved space, which also covers the broadcast address.
			if ( ( $a & 0xF0 ) === 240 ) {
				return false;
			}

			return true;
		}

		// PHP rejects every IPv6 literal with an embedded dotted quad.
		if ( strpos( $ip, '.' ) !== false ) {
			return false;
		}

		// The unspecified address and loopback.
		if ( $bin === str_repeat( "\x00", 16 ) || $bin === str_repeat( "\x00", 15 ) . "\x01" ) {
			return false;
		}

		// IPv4-mapped ::ffff:0:0/96, checked in bytes: it also has a hex-only spelling.
		if ( substr( $bin, 0, 10 ) === str_repeat( "\x00", 10 ) && substr( $bin, 10, 2 ) === "\xFF\xFF" ) {
			return false;
		}

		$first = ord( $bin[0] );

		// Unique-local addresses.
		if ( ( $first & 0xFE ) === 0xFC ) {
			return false;
		}

		// Link-local addresses.
		if ( $first === 0xFE && ( ord( $bin[1] ) & 0xC0 ) === 0x80 ) {
			return false;
		}

		return true;
	}
}
