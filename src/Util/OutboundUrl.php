<?php
/**
 * Outbound request guards.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

use PerfLocale\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outbound request guards: URL and address checks, DNS-pinned requests, the dispatch gate.
 *
 * Kept out of Helper so visitor pages do not compile it; Helper forwards its @api methods here.
 */
final class OutboundUrl {

	/**
	 * Machine-translation provider address setting, mapped to the key setting sent to it.
	 */
	private const MT_KEY_FOR_URL = [
		'mt_libre_url'  => 'mt_libre_api_key',
		'mt_agency_url' => 'mt_agency_api_key',
	];

	/**
	 * Public provider hosts that skip the SSRF lookup (gethostbyname() has no timeout and hangs on a broken resolver).
	 */
	private const MT_TRUSTED_HOSTS = [
		'translation.googleapis.com',
		'api.deepl.com',
		'api-free.deepl.com',
		'api.cognitive.microsofttranslator.com',
	];

	/**
	 * Set by provider_address_rules(); read by refused_plain_http().
	 *
	 * @var bool
	 */
	private static bool $refused_plain_http = false;

	/**
	 * True when `$ip` is a syntactically valid IPv6 literal.
	 *
	 * @see Helper::is_ip()
	 *
	 * @param string $ip Candidate address.
	 */
	public static function is_ipv6( string $ip ): bool {
		if ( ! function_exists( 'inet_pton' ) || $ip === '' || strpos( $ip, "\0" ) !== false ) {
			return false;
		}

		$bin = inet_pton( $ip );

		return $bin !== false && strlen( $bin ) === 16;
	}


	/**
	 * True when `$url` parses as a syntactically valid URL; uses filter_var when it exists.
	 *
	 * The fallback is stricter than filter_var, never looser (it guards SSRF checks); see docs-ai/11-code-rationale.md.
	 *
	 * @param string $url Candidate URL.
	 */
	public static function is_valid_url( string $url ): bool {
		if ( function_exists( 'filter_var' ) ) {
			return filter_var( $url, FILTER_VALIDATE_URL ) !== false;
		}

		$len = strlen( $url );

		if ( $len === 0 || $len > 2048 ) {
			return false;
		}

		// Printable ASCII only, as FILTER_VALIDATE_URL.
		for ( $i = 0; $i < $len; $i++ ) {
			$ord = ord( $url[ $i ] );

			if ( $ord < 0x21 || $ord > 0x7E ) {
				return false;
			}
		}

		// Every per cent must introduce exactly two hex digits, as filter_var requires.
		$pos = 0;

		while ( ( $pos = strpos( $url, '%', $pos ) ) !== false ) {
			if ( $pos + 2 >= $len || strspn( substr( $url, $pos + 1, 2 ), '0123456789abcdefABCDEF' ) !== 2 ) {
				return false;
			}

			$pos += 3;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() is deliberately NOT used: this branch runs only when ext-filter is absent, and it must stay loadable without WordPress so the gate can exercise it in a subprocess. The two differ only for protocol-relative and root-relative URLs, which the scheme test below rejects anyway.
		$parts = parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) ) {
			return false;
		}

		// Userinfo refused outright: filter_var's own userinfo rule cannot be matched without looser cases.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$host = (string) $parts['host'];

		if ( $host[0] === '[' ) {
			// Brackets only around IPv6: filter_var rejects a bracketed IPv4, hence the length test.
			if ( substr( $host, -1 ) !== ']' || ! function_exists( 'inet_pton' ) ) {
				return false;
			}

			$literal = substr( $host, 1, -1 );

			if ( $literal === '' || strpos( $literal, "\0" ) !== false ) {
				return false;
			}

			$packed = inet_pton( $literal );

			return $packed !== false && strlen( $packed ) === 16;
		}

		$probe = substr( $host, -1 ) === '.' ? substr( $host, 0, -1 ) : $host;

		if ( $probe === '' || strlen( $probe ) > 253 ) {
			return false;
		}

		foreach ( explode( '.', $probe ) as $label ) {
			$label_len = strlen( $label );

			if ( $label_len === 0 || $label_len > 63 ) {
				return false;
			}

			if ( $label[0] === '-' || $label[ $label_len - 1 ] === '-' ) {
				return false;
			}

			if ( strspn( $label, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-' ) !== $label_len ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * True when `$url` uses https.
	 *
	 * @since 1.0.7.5
	 *
	 * @param string $url Candidate URL.
	 */
	public static function is_https( string $url ): bool {
		return 'https' === strtolower( (string) wp_parse_url( trim( $url ), PHP_URL_SCHEME ) );
	}

	/**
	 * Lowercase hosts machine-translation requests trust (no SSRF address or DNS check); the agency URL's http rule
	 * reads the same list.
	 *
	 * @since 1.0.7.5
	 *
	 * @return array<int, string>
	 */
	public static function mt_trusted_hosts(): array {
		/**
		 * Filter the hosts that skip the SSRF address and DNS checks: the way to reach a provider on an internal network.
		 * Hosts added here may also serve the Agency Endpoint URL over http://.
		 *
		 * A full exemption, IP literals included (add only hosts you control); localhost, 127.0.0.1, 0.0.0.0 and [::1]
		 * only when listed exactly.
		 *
		 * @hook perflocale/mt/trusted_hosts
		 * @param array<int, string> $hosts Lowercase trusted hostnames.
		 */
		$trusted = (array) apply_filters( 'perflocale/mt/trusted_hosts', self::MT_TRUSTED_HOSTS );

		return array_values( array_map( 'strtolower', array_filter( $trusted, 'is_string' ) ) );
	}

	/**
	 * True when `$url` is https, or http:// to a host added to perflocale/mt/trusted_hosts (a local or Docker endpoint).
	 * The built-in public provider hosts stay https-only.
	 *
	 * @since 1.0.7.5
	 *
	 * @param string $url Candidate URL.
	 */
	public static function is_https_or_trusted( string $url ): bool {
		if ( self::is_https( $url ) ) {
			return true;
		}

		$parts = wp_parse_url( trim( $url ) );
		$host  = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';

		if ( '' === $host || 'http' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return false;
		}

		return in_array( $host, array_diff( self::mt_trusted_hosts(), self::MT_TRUSTED_HOSTS ), true );
	}

	/**
	 * True when a save must refuse `$url`: a new, non-empty value that is not https (nor http:// to a trusted host). The
	 * stored value is never refused.
	 *
	 * @since 1.0.7.5
	 *
	 * @param mixed $url     Value being saved; a non-scalar is refused.
	 * @param mixed $current Value stored now.
	 */
	public static function refuses_new_plain_http( mixed $url, mixed $current ): bool {
		if ( ! is_scalar( $url ) ) {
			return null !== $url;
		}

		$url = trim( (string) $url );

		return '' !== $url && $url !== ( is_scalar( $current ) ? trim( (string) $current ) : '' ) && ! self::is_https_or_trusted( $url );
	}

	/**
	 * Whether the last Settings::update() that took its lock refused a new agency URL that is not https.
	 *
	 * @since 1.0.7.5
	 */
	public static function refused_plain_http(): bool {
		return self::$refused_plain_http;
	}

	/**
	 * Clear refused_plain_http() as Settings::update() starts, so a save that never took the lock reports no refusal.
	 *
	 * @since 1.0.7.5
	 */
	public static function forget_refusal(): void {
		self::$refused_plain_http = false;
	}

	/**
	 * Settings::update()'s provider address rules, judged against the row read inside its lock.
	 *
	 * A new agency URL must be https, else it and the key typed with it are dropped. A changed address clears a stored
	 * key unless the update carries one; on multisite only a super admin or WP-CLI may re-point an externally supplied key.
	 *
	 * @since 1.0.7.5
	 *
	 * @param \PerfLocale\Settings $settings  Settings being updated.
	 * @param array<string, mixed> $current   Stored settings, read inside the update's lock.
	 * @param array<string, mixed> $sanitized The update's sanitized values.
	 * @return array{0: array<string, mixed>, 1: array<int, string>} The values to merge, and the refused address settings.
	 */
	public static function provider_address_rules( \PerfLocale\Settings $settings, array $current, array $sanitized ): array {
		$refused = [];

		self::$refused_plain_http = array_key_exists( 'mt_agency_url', $sanitized ) && self::refuses_new_plain_http( $sanitized['mt_agency_url'], $current['mt_agency_url'] ?? '' );

		if ( self::$refused_plain_http ) {
			unset( $sanitized['mt_agency_url'], $sanitized['mt_agency_api_key'] );
		}

		foreach ( self::MT_KEY_FOR_URL as $url_key => $secret_key ) {
			if ( ! array_key_exists( $url_key, $sanitized ) || $sanitized[ $url_key ] === ( $current[ $url_key ] ?? '' ) ) {
				continue;
			}

			if ( $settings->is_overridden( $secret_key ) ) {
				if ( is_multisite() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! is_super_admin() ) {
					unset( $sanitized[ $url_key ] );
					$refused[] = $url_key;
				}

				continue;
			}

			if ( '' === ( $sanitized[ $secret_key ] ?? '' ) ) {
				$sanitized[ $secret_key ] = '';
			}
		}

		return [ $sanitized, $refused ];
	}


	/**
	 * True when an outbound request (webhook, machine translation) may connect to `$ip`.
	 *
	 * Refuses what is_public_ipv4() refuses plus the IANA blocks that are not globally reachable, as core does.
	 * Internal MT endpoints go through the `perflocale/mt/trusted_hosts` filter, never through this check.
	 *
	 * @param string $ip Candidate address.
	 */
	public static function is_public_outbound_address( string $ip ): bool {
		if ( ! Helper::is_public_ipv4( $ip ) ) {
			return false;
		}

		$bin = inet_pton( $ip );

		if ( $bin === false ) {
			return false;
		}

		if ( strlen( $bin ) === 4 ) {
			$a = ord( $bin[0] );
			$b = ord( $bin[1] );
			$c = ord( $bin[2] );

			// Shared address space (carrier-grade NAT), 100.64.0.0/10.
			if ( $a === 100 && ( $b & 0xC0 ) === 64 ) {
				return false;
			}

			// IETF 192.0.0.0/24, documentation 192.0.2.0/24, 6to4 relay 192.88.99.0/24.
			if ( $a === 192 && ( ( $b === 0 && ( $c === 0 || $c === 2 ) ) || ( $b === 88 && $c === 99 ) ) ) {
				return false;
			}

			// Benchmarking 198.18.0.0/15 and documentation 198.51.100.0/24.
			if ( $a === 198 && ( ( $b & 0xFE ) === 18 || ( $b === 51 && $c === 100 ) ) ) {
				return false;
			}

			// Documentation 203.0.113.0/24.
			if ( $a === 203 && $b === 0 && $c === 113 ) {
				return false;
			}

			// Multicast 224.0.0.0/4.
			if ( ( $a & 0xF0 ) === 224 ) {
				return false;
			}

			return true;
		}

		// NAT64 64:ff9b::/96: judged by the IPv4 address in the last four bytes.
		if ( substr( $bin, 0, 12 ) === "\x00\x64\xFF\x9B" . str_repeat( "\x00", 8 ) ) {
			$embedded = inet_ntop( substr( $bin, 12, 4 ) );

			return false !== $embedded && self::is_public_outbound_address( $embedded );
		}

		// 6to4 2002::/16: the IPv4 address is in bytes 2 to 5.
		if ( substr( $bin, 0, 2 ) === "\x20\x02" ) {
			$embedded = inet_ntop( substr( $bin, 2, 4 ) );

			return false !== $embedded && self::is_public_outbound_address( $embedded );
		}

		// IPv4-compatible ::/96 (which also holds :: and ::1).
		if ( substr( $bin, 0, 12 ) === str_repeat( "\x00", 12 ) ) {
			return false;
		}

		// IPv4-translated ::ffff:0:0:0/96.
		if ( substr( $bin, 0, 12 ) === str_repeat( "\x00", 8 ) . "\xFF\xFF\x00\x00" ) {
			return false;
		}

		// Local-use NAT64 64:ff9b:1::/48.
		if ( substr( $bin, 0, 6 ) === "\x00\x64\xFF\x9B\x00\x01" ) {
			return false;
		}

		// Teredo 2001::/32, ORCHID 2001:10::/28 and 2001:20::/28, documentation 2001:db8::/32.
		if ( substr( $bin, 0, 2 ) === "\x20\x01" ) {
			$third  = ord( $bin[2] );
			$fourth = ord( $bin[3] );

			if ( $third === 0x00 && ( $fourth === 0x00 || ( $fourth & 0xF0 ) === 0x10 || ( $fourth & 0xF0 ) === 0x20 ) ) {
				return false;
			}

			if ( $third === 0x0D && $fourth === 0xB8 ) {
				return false;
			}
		}

		// Documentation 3fff::/20.
		if ( substr( $bin, 0, 2 ) === "\x3F\xFF" && ( ord( $bin[2] ) & 0xF0 ) === 0x00 ) {
			return false;
		}

		// Discard-only 100::/64.
		if ( substr( $bin, 0, 8 ) === "\x01\x00" . str_repeat( "\x00", 6 ) ) {
			return false;
		}

		$first = ord( $bin[0] );

		// Site-local fec0::/10.
		if ( $first === 0xFE && ( ord( $bin[1] ) & 0xC0 ) === 0xC0 ) {
			return false;
		}

		// Multicast ff00::/8.
		if ( $first === 0xFF ) {
			return false;
		}

		return true;
	}


	/**
	 * Run wp_remote_request() pinned to addresses the caller already judged, against DNS rebinding.
	 *
	 * cURL gets CURLOPT_RESOLVE; fsockopen https re-checks the lookup, since a rewritten socket breaks SNI.
	 * Nothing is pinned without a valid `$ip` or behind a proxy; see docs-ai/11-code-rationale.md.
	 *
	 * @param string       $url  Request URL.
	 * @param array<mixed> $args wp_remote_request() arguments.
	 * @param string       $ip   Address to connect to; '' for none.
	 * @param array<mixed> $more Further judged addresses of the same host, listed after `$ip`.
	 * @return array<string, mixed>|\WP_Error wp_remote_request()'s result.
	 */
	public static function remote_request_pinned( string $url, array $args, string $ip, array $more = [] ): array|\WP_Error {
		$pins = self::connection_pins( $url, $ip, $more );

		foreach ( $pins as $hook => $pin ) {
			add_action( $hook, $pin, 10, 1 );
		}

		try {
			return wp_remote_request( $url, $args );
		} finally {
			foreach ( $pins as $hook => $pin ) {
				remove_action( $hook, $pin, 10 );
			}
		}
	}


	/**
	 * The two transport callbacks {@see remote_request_pinned()} hooks for one
	 * request, keyed by hook name; empty when nothing is pinned.
	 *
	 * @param string       $url  Request URL.
	 * @param string       $ip   Address to connect to; '' for none.
	 * @param array<mixed> $more Further judged addresses, listed after `$ip`.
	 * @return array<string, \Closure>
	 */
	private static function connection_pins( string $url, string $ip, array $more = [] ): array {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $ip || ! is_string( $host ) || '' === $host || ! Helper::is_ip( $ip ) ) {
			return [];
		}

		$proxy = new \WP_HTTP_Proxy();

		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			return [];
		}

		$host    = strtolower( $host );
		$https   = 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$port    = (int) wp_parse_url( $url, PHP_URL_PORT );
		$port    = $port > 0 ? $port : ( $https ? 443 : 80 );
		$address = self::is_ipv6( $ip ) ? '[' . $ip . ']' : $ip;
		$list    = [ $address ];

		foreach ( $more as $other ) {
			if ( is_string( $other ) && Helper::is_ip( $other ) ) {
				$list[] = self::is_ipv6( $other ) ? '[' . $other . ']' : $other;
			}
		}

		$entry = $host . ':' . $port . ':' . implode( ',', array_unique( $list ) );

		return [
			'http_api_curl'                    => static function ( $handle ) use ( $entry ): void {
				if ( $handle instanceof \CurlHandle ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- one option on the handle WP_Http created for this wp_remote_request().
					curl_setopt( $handle, CURLOPT_RESOLVE, [ $entry ] );
				}
			},
			'requests-fsockopen.remote_socket' => static function ( &$remote_socket ) use ( $host, $port, $address, $https ): void {
				if ( ! is_string( $remote_socket ) ) {
					return;
				}

				$socket = strtolower( $remote_socket );

				if ( ! $https && 'tcp://' . $host . ':' . $port === $socket ) {
					$remote_socket = 'tcp://' . $address . ':' . $port;

					return;
				}

				if ( $https && 'ssl://' . $host . ':' . $port === $socket && ! self::is_public_outbound_address( gethostbyname( $host ) ) ) {
					// The throw skips Requests' own restore of its connect error handler.
					restore_error_handler();

					throw new \WpOrg\Requests\Exception( 'The host resolves to a non-public address.', 'perflocale_pin' );
				}
			},
		];
	}


	/**
	 * Whether outward-facing dispatches (webhooks) may fire from this environment.
	 *
	 * Fail-closed outside 'production': staging clones carry the production webhook URLs.
	 *
	 * @param string $feature Dispatch surface ('webhooks'), so a filter can allow one surface only.
	 * @return bool True when dispatch may proceed.
	 */
	public static function is_outbound_dispatch_allowed( string $feature ): bool {
		$environment = wp_get_environment_type();

		if ( $environment === 'production' ) {
			return true;
		}

		/**
		 * Allow outward-facing dispatches from non-production environments.
		 *
		 * @hook perflocale/dispatch/allow_non_production
		 *
		 * @param bool   $allow       Default false.
		 * @param string $feature     Dispatch surface ('webhooks').
		 * @param string $environment Current environment type.
		 */
		if ( (bool) apply_filters( 'perflocale/dispatch/allow_non_production', false, $feature, $environment ) ) {
			return true;
		}

		/**
		 * Fires when an outward-facing dispatch is blocked by the non-production environment gate.
		 *
		 * @hook perflocale/dispatch/blocked
		 *
		 * @param string $feature     Dispatch surface that was blocked.
		 * @param string $environment Current environment type.
		 */
		do_action( 'perflocale/dispatch/blocked', $feature, $environment );

		return false;
	}
}
