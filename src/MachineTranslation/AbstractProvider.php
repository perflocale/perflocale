<?php
/**
 * Abstract machine translation provider.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

use PerfLocale\Helper;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base of the machine translation providers: guarded requests, retries with backoff, the circuit breaker and usage
 * counting.
 */
abstract class AbstractProvider implements ProviderInterface {

	/**
	 * Public provider hosts that skip the SSRF lookup (gethostbyname() has no timeout and hangs on a broken resolver).
	 *
	 * @var array<int, string>
	 */
	private const TRUSTED_HOSTS = [
		'translation.googleapis.com',
		'api.deepl.com',
		'api-free.deepl.com',
		'api.cognitive.microsofttranslator.com',
	];

	/**
	 * Provider id => the setting holding its credential, masked by exact value in error text.
	 *
	 * @var array<string, string>
	 */
	private const CREDENTIAL_SETTING = [
		'deepl'           => 'mt_deepl_api_key',
		'google'          => 'mt_google_api_key',
		'microsoft'       => 'mt_microsoft_api_key',
		'libretranslate'  => 'mt_libre_api_key',
		'external_agency' => 'mt_agency_api_key',
	];

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	protected readonly Settings $settings;

	/**
	 * Characters translated in current session.
	 *
	 * @var int
	 */
	protected int $session_usage = 0;

	/**
	 * Breaker key of a 2xx response whose body is not yet proven usable ('' for none).
	 *
	 * @var string
	 */
	private string $pending_success_key = '';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * A language code in lowercase dash form ('en_US', 'EN-US' => 'en-us'); providers apply their own rules on top.
	 *
	 * @param string $code Input language code.
	 * @return string Lowercased, dash-separated code.
	 */
	protected function normalize_language_code( string $code ): string {
		return strtolower( str_replace( '_', '-', trim( $code ) ) );
	}

	/**
	 * A language code's base ISO 639-1 form, for providers that take no variants.
	 *
	 * @param string $code Input language code.
	 * @return string Base ISO 639-1 code in lowercase.
	 */
	protected function base_language_code( string $code ): string {
		$normalized = $this->normalize_language_code( $code );

		if ( strpos( $normalized, '-' ) !== false ) {
			return explode( '-', $normalized, 2 )[0];
		}

		return $normalized;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $format 'html' (default) or 'text' (no entity escaping, for plain-text destinations). Not on
	 *   ProviderInterface: that would fatal third-party implementations, which ignore an extra argument.
	 */
	public function translate_batch( array $texts, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): array {
		$results = [];

		foreach ( $texts as $text ) {
			$results[] = $this->translate( $text, $source_lang, $target_lang, $fast_fail, $format );
		}

		return $results;
	}

	/**
	 * Whether placeholders (block, shortcode, "Do not translate") go as empty elements, for a provider that returns HTML comments as text.
	 *
	 * @since 1.0.7.4
	 * @return bool
	 */
	public function placeholders_as_elements(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_usage(): int {
		return $this->session_usage;
	}

	/**
	 * Make an HTTP request with retry logic, SSL enforcement, and SSRF protection.
	 *
	 * @param string               $url API URL.
	 * @param array<string, mixed> $args Request arguments for wp_remote_request().
	 * @param int                  $retries Number of retries on failure.
	 * @return array<string, mixed> Response array with 'code' and 'body'.
	 *
	 * @throws \RuntimeException If all retries fail or URL is blocked.
	 * @throws \PerfLocale\Concurrency\BreakerOpenException When the circuit breaker for this provider is open.
	 * @throws DeadlineReachedException When a call or wait does not fit before a slice's deadline ({@see CallDeadline}).
	 */
	protected function make_request( string $url, array $args, int $retries = 3, bool $fast_fail = false ): array {
		// SSRF check; for a host name each attempt connects to the address it judged (Helper::remote_request_pinned()).
		// Docs: docs-ai/11-code-rationale.md, "AbstractProvider: request guards".
		$pinned_ip = $this->validate_url( $url );

		if ( '' !== $pinned_ip && self::proxy_refuses( $url ) ) {
			throw new \RuntimeException( 'Machine translation refused: this site sends the provider request through an HTTP proxy, and a filter on this site refuses proxied requests to custom hosts.' );
		}

		// An open breaker throws at once so callers can fall back without burning the retries; `mt_{id}` is the key
		// Site Health shows.
		$breaker_key = 'mt_' . $this->get_id();

		// Drop a success armed by an earlier call whose caller never confirmed it.
		$this->pending_success_key = '';

		if ( \PerfLocale\Concurrency\Breaker::is_open( $breaker_key ) ) {
			$status = \PerfLocale\Concurrency\Breaker::status( $breaker_key );
			// The key is a sanitized slug; esc_html() satisfies the escaped-exception rule.
			throw new \PerfLocale\Concurrency\BreakerOpenException(
				esc_html( $breaker_key ),
				esc_html(
					sprintf(
						/* translators: 1: Provider name, 2: seconds until probe */
						__( 'Translation provider %1$s is temporarily unreachable (circuit breaker open; retry in %2$ds).', 'perflocale' ),
						$this->get_name(),
						(int) ( $status['cooldown_remaining'] ?? 0 )
					)
				)
			);
		}

		if ( $fast_fail ) {
			$retries         = 1;
			$args['timeout'] = min( (int) ( $args['timeout'] ?? 30 ), 8 );
		}

		// SSL verification on.
		if ( ! isset( $args['sslverify'] ) ) {
			$args['sslverify'] = true;
		}

		// No redirects: a validated public host could redirect into an internal one. Not reject_unsafe_urls (it refuses
		// ports like a self-hosted LibreTranslate's :5000). Set only when absent, so request_args can change it.
		if ( ! isset( $args['redirection'] ) ) {
			$args['redirection'] = 0;
		}

		// Cap the buffered response body at 8 x the payload + 16 KB (at least 64 KB): a runaway reply would be a fatal.
		// Over the cap the body is cut silently and fails as a JSON parse error; set only when absent.
		if ( ! isset( $args['limit_response_size'] ) ) {
			$payload = $args['body'] ?? '';

			if ( is_array( $payload ) ) {
				$encoded = wp_json_encode( $payload );
				$payload = is_string( $encoded ) ? $encoded : '';
			}

			$payload_bytes = is_string( $payload ) ? strlen( $payload ) : 0;

			$args['limit_response_size'] = max( 65536, ( 8 * $payload_bytes ) + 16384 );
		}

		/**
		 * Filter the wp_remote_request() arguments sent to a machine-translation provider (proxies, longer timeouts,
		 * tracing headers). sslverify stays on unless set to false.
		 *
		 * @hook perflocale/mt/request_args
		 *
		 * @param array $args wp_remote_request() argument array.
		 * @param string $url Destination URL (already SSRF-validated).
		 * @param string $provider_id Provider slug (e.g. 'deepl', 'google').
		 */
		$args = (array) apply_filters( 'perflocale/mt/request_args', $args, $url, $this->get_id() );

		// WordPress's default when nothing sets one.
		$requested = is_numeric( $args['timeout'] ?? null ) ? (float) $args['timeout'] : 5.0;
		$started   = microtime( true );

		$last_error     = null;
		$failure_reason = '';

		for ( $attempt = 1; $attempt <= $retries; $attempt++ ) {
			// A retry judges the host again (after a transport error the cached DNS answers are dropped below).
			if ( $attempt > 1 ) {
				$pinned_ip = $this->validate_url( $url );
			}

			// Clamped after the filter, so no filtered timeout outlasts a slice's request.
			$call_args = $args;
			$timeout   = CallDeadline::timeout( $requested );

			if ( $timeout < $requested ) {
				$call_args['timeout'] = $timeout;
			}

			$response = Helper::remote_request_pinned( $url, $call_args, $pinned_ip );

			if ( is_wp_error( $response ) ) {
				// A transport error message can carry the outgoing request (filters, custom transports, proxies) and ends up on
				// the job row: masked like a response body.
				$last_error     = $this->mask_error_text( $response->get_error_message() );
				$failure_reason = 'transient';

				// The host may have moved: drop its cached DNS answers.
				if ( '' !== $pinned_ip ) {
					self::forget_dns( $url );
				}

				if ( $attempt < $retries ) {
					// Exponential backoff: 1s, 2s, 4s.
					$backoff = (int) pow( 2, $attempt - 1 );

					if ( CallDeadline::wait( $backoff ) ) {
						continue;
					}

					self::stop_unless_slow( max( $timeout, microtime( true ) - $started ), $backoff );
				}

				break;
			}

			$code = wp_remote_retrieve_response_code( $response );

			// 429: wait for Retry-After, capped (default 300 s) so a hostile value cannot hold the worker for hours.
			if ( $code === 429 && $attempt < $retries ) {
				$retry_after_header = wp_remote_retrieve_header( $response, 'retry-after' );
				$retry_after        = self::parse_retry_after( $retry_after_header );
				$wait               = max( $retry_after, (int) pow( 2, $attempt ) );
				/**
				 * Filter the upper cap on the Retry-After sleep (seconds).
				 *
				 * @hook perflocale/mt/retry_after_max_seconds
				 * @param int $max_seconds Default 300.
				 */
				$max_wait = (int) apply_filters( 'perflocale/mt/retry_after_max_seconds', 300 );
				$wait     = min( $wait, max( 1, $max_wait ) );

				// A wait past the slice's deadline: the job continues at the provider's time.
				if ( ! CallDeadline::wait( $wait ) ) {
					\PerfLocale\Concurrency\Breaker::record_failure( $breaker_key, 'rate_limit' );

					throw new DeadlineReachedException( time() + $wait, true ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int; the message is escaped in the class.
				}

				// A rate-limit wait is not the provider failing: it does not count toward the call's window.
				$started       += $wait;
				$failure_reason = 'rate_limit';
				continue;
			}

			if ( $code >= 200 && $code < 300 ) {
				// 200 is not yet success: an unusable body must count as a failure, so parse_json_response() settles it.
				// Docs: docs-ai/11-code-rationale.md, "AbstractProvider: breaker accounting".
				$this->pending_success_key = $breaker_key;

				$body = wp_remote_retrieve_body( $response );

				return [
					'code' => $code,
					'body' => $body,
				];
			}

			// Masked and truncated: error text reaches the client and the logs.
			$error_body = mb_substr( $this->mask_configured_credential( wp_remote_retrieve_body( $response ) ), 0, 200 );
			$error_body = self::mask_credentials( $error_body );
			$last_error = sprintf( 'HTTP %d: %s', $code, $error_body );

			// 401/403 trips the breaker on the first hit (retries cannot fix a bad key); 429 and 5xx use the sliding window.
			if ( $code === 401 || $code === 403 ) {
				$failure_reason = 'auth';
			} elseif ( $code === 429 ) {
				$failure_reason = 'rate_limit';
			} elseif ( $code >= 500 ) {
				$failure_reason = 'transient';
			} else {
				$failure_reason = 'http_' . $code;
			}

			if ( $code >= 400 && $code < 500 && $code !== 429 ) {
				// Client error: no retry.
				break;
			}

			// 5xx: back off 1 s, 2 s like a transport error instead of hammering an overloaded endpoint.
			if ( $code >= 500 && $attempt < $retries ) {
				$backoff = (int) pow( 2, $attempt - 1 );

				if ( ! CallDeadline::wait( $backoff ) ) {
					self::stop_unless_slow( max( $timeout, microtime( true ) - $started ), $backoff );
					break;
				}
			}
		}

		// Only provider-health failures count toward the breaker (auth, rate_limit, transient); a per-request 4xx such as
		// an unsupported language pair does not, or one bad batch would take the provider offline.
		if ( in_array( $failure_reason, [ 'auth', 'rate_limit', 'transient' ], true ) ) {
			$threshold_override = $failure_reason === 'auth' ? 1 : 0;
			\PerfLocale\Concurrency\Breaker::record_failure( $breaker_key, $failure_reason, $threshold_override );
		}

		throw new \RuntimeException(
			esc_html(
				sprintf(
					/* translators: 1: Provider name, 2: failure category, 3: Error message */
					__( 'Translation API error (%1$s) [%2$s]: %3$s', 'perflocale' ),
					$this->get_name(),
					$failure_reason !== '' ? $failure_reason : 'unknown',
					$last_error ?? __( 'Unknown error', 'perflocale' )
				)
			)
		);
	}

	/**
	 * No time left for a retry: stop the slice so the next one retries, unless this call (all attempts) had a long
	 * window and failed on its own; then it fails, so a slow provider cannot loop.
	 *
	 * @param float $timeout Seconds this call had: its attempts so far, or the failed attempt's timeout.
	 * @param int   $backoff Wait before the next attempt.
	 * @return void
	 * @throws DeadlineReachedException When the window was short.
	 */
	private static function stop_unless_slow( float $timeout, int $backoff ): void {
		if ( $timeout < CallDeadline::SLOW_CALL_SECONDS ) {
			throw new DeadlineReachedException( time() + $backoff ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int; the message is escaped in the class.
		}
	}

	/**
	 * Decode a JSON body (a list for Microsoft, an object for DeepL and Google) and settle the armed success.
	 *
	 * @param string $body Raw response body.
	 * @return array<int|string, mixed> Decoded JSON data.
	 *
	 * @throws \RuntimeException If JSON is invalid.
	 */
	protected function parse_json_response( string $body ): array {
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->reject_response( 'malformed' );

			throw new \RuntimeException(
				esc_html(
					sprintf(
						'Invalid JSON response from %s: %s',
						$this->get_name(),
						json_last_error_msg()
					)
				)
			);
		}

		if ( ! is_array( $data ) ) {
			$this->reject_response( 'malformed' );

			throw new \RuntimeException(
				esc_html( sprintf( 'Unexpected response format from %s.', $this->get_name() ) )
			);
		}

		$this->accept_response();

		return $data;
	}

	/**
	 * Confirm the last 2xx body was usable, for a provider that reads make_request()'s body without
	 * parse_json_response(); otherwise an old failure streak lingers.
	 *
	 * @return void
	 */
	protected function accept_response(): void {
		if ( $this->pending_success_key === '' ) {
			return;
		}

		$key                       = $this->pending_success_key;
		$this->pending_success_key = '';

		\PerfLocale\Concurrency\Breaker::record_success( $key );
	}

	/**
	 * Report a 2xx body that could not be used; counts toward the breaker like a transport failure.
	 *
	 * @param string $reason Short failure tag stored for Site Health.
	 * @return void
	 */
	protected function reject_response( string $reason = 'malformed' ): void {
		$key                       = $this->pending_success_key !== '' ? $this->pending_success_key : 'mt_' . $this->get_id();
		$this->pending_success_key = '';

		\PerfLocale\Concurrency\Breaker::record_failure( $key, $reason );
	}

	/**
	 * Refuse a URL that targets localhost, a private range or a cloud metadata address.
	 *
	 * @param string $url      URL to validate.
	 * @param bool   $resolved Whether the URL holds an address a host name resolved to.
	 * @return string For a host name, the IPv4 address it resolved to and that
	 *   was judged here (the request is pinned to it); '' for a host on
	 *   perflocale/mt/trusted_hosts and for an IP literal.
	 *
	 * @throws \RuntimeException If URL targets an internal network.
	 */
	private function validate_url( string $url, bool $resolved = false ): string {
		$parsed = wp_parse_url( $url );
		$host   = $parsed['host'] ?? '';

		if ( $host === '' ) {
			throw new \RuntimeException( 'Invalid provider URL: missing host.' );
		}

		$host_lc = strtolower( $host );

		/**
		 * Filter the hosts that skip the SSRF address and DNS checks: the way to reach a provider on an internal network.
		 *
		 * A full exemption, IP literals included (add only hosts you control); localhost, 127.0.0.1, 0.0.0.0 and [::1]
		 * only when listed exactly.
		 *
		 * @hook perflocale/mt/trusted_hosts
		 * @param array<int, string> $hosts Lowercase trusted hostnames.
		 */
		$trusted = (array) apply_filters( 'perflocale/mt/trusted_hosts', self::TRUSTED_HOSTS );
		$trusted = array_map( 'strtolower', array_filter( $trusted, 'is_string' ) );

		// Loopback only as the URL's own listed host, never as an address another name resolves to.
		if ( in_array( $host_lc, [ 'localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]' ], true ) && ( $resolved || ! in_array( $host_lc, $trusted, true ) ) ) {
			throw new \RuntimeException( 'Provider URL cannot target localhost.' );
		}

		if ( in_array( $host_lc, $trusted, true ) ) {
			return '';
		}

		// wp_parse_url() keeps IPv6 hosts in brackets; filter_var() and inet_pton() want the bare literal.
		$host_ip = ( strlen( $host ) >= 2 && $host[0] === '[' && $host[-1] === ']' )
			? substr( $host, 1, -1 )
			: $host;

		// Judge an IPv4-mapped IPv6 address as the IPv4 it carries (both spellings get the same rule), as
		// WebhookController::is_url_safe() does; keep them in sync.
		if ( Helper::is_ipv6( $host_ip ) ) {
			$host_bin = inet_pton( $host_ip );

			if ( false !== $host_bin && 16 === strlen( $host_bin ) && "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" === substr( $host_bin, 0, 12 ) ) {
				$unwrapped = inet_ntop( substr( $host_bin, 12 ) );

				if ( false !== $unwrapped ) {
					$host_ip = $unwrapped;
				}
			}
		}

		// An IP literal needs no DNS.
		if ( Helper::is_ip( $host_ip ) ) {
			// Both families; the fc00::/7 and fe80::/10 checks below are a second layer.
			if ( ! Helper::is_public_outbound_address( $host_ip ) ) {
				throw new \RuntimeException( 'Provider URL targets a private or reserved IP address. To use a provider on an internal network, add its host to the perflocale/mt/trusted_hosts filter.' );
			}

			// Unique-local fc00::/7 and link-local fe80::/10, again as a second layer (as WebhookController::is_url_safe()).
			if ( Helper::is_ipv6( $host_ip ) ) {
				$bin = inet_pton( $host_ip );

				if ( $bin !== false && strlen( $bin ) === 16 ) {
					$first  = ord( $bin[0] );
					$second = ord( $bin[1] );

					// fc00::/7: first 7 bits 1111110.
					if ( ( $first & 0xFE ) === 0xFC ) {
						throw new \RuntimeException( 'Provider URL targets an IPv6 unique-local address.' );
					}

					// fe80::/10: first 10 bits 1111111010.
					if ( $first === 0xFE && ( $second & 0xC0 ) === 0x80 ) {
						throw new \RuntimeException( 'Provider URL targets an IPv6 link-local address.' );
					}
				}
			}

			return '';
		}

		// DNS answers cached for 5 minutes: gethostbyname() has no timeout and a broken resolver blocks for long.
		$cache_key = 'perflocale_dns_' . \PerfLocale\Cache\CacheManager::flush_scoped_hash( $host );
		$ip        = get_transient( $cache_key );

		if ( $ip === false ) {
			$ip = gethostbyname( $host );
			set_transient( $cache_key, $ip, 5 * MINUTE_IN_SECONDS );
		}

		// Fail closed (as WebhookController::is_url_safe()): gethostbyname() returns its input on failure and does not
		// canonicalise hex or octal literals, so a real public address is required.
		if ( ! is_string( $ip ) || $ip === $host || ! Helper::is_ip( $ip ) ) {
			throw new \RuntimeException( 'Provider URL could not be resolved to a verifiable public IP.' );
		}

		if ( ! Helper::is_public_outbound_address( $ip ) ) {
			throw new \RuntimeException( 'Provider URL resolves to a private or reserved IP address. To use a provider on an internal network, add its host to the perflocale/mt/trusted_hosts filter.' );
		}

		// Loopback 127.0.0.0/8, a second layer as in WebhookController::is_url_safe().
		if ( str_starts_with( $ip, '127.' ) ) {
			throw new \RuntimeException( 'Provider URL resolves to a loopback address.' );
		}

		// gethostbyname() is IPv4-only: each AAAA record goes through this method as a literal, so both spellings get the
		// same rules (one level deep).
		foreach ( self::resolve_aaaa( $host ) as $ipv6 ) {
			try {
				$this->validate_url( 'https://[' . $ipv6 . ']/', true );
			} catch ( \RuntimeException ) {
				throw new \RuntimeException( 'Provider URL resolves to a private or reserved IPv6 address. To use a provider on an internal network, add its host to the perflocale/mt/trusted_hosts filter.' );
			}
		}

		return $ip;
	}

	/**
	 * Whether the site refuses to send this request to a resolved host through the HTTP proxy.
	 *
	 * The proxy resolves the host itself, so the address validate_url() judged cannot be pinned.
	 *
	 * @param string $url Request URL.
	 * @return bool
	 */
	private static function proxy_refuses( string $url ): bool {
		$proxy = new \WP_HTTP_Proxy();

		if ( ! $proxy->is_enabled() || ! $proxy->send_through_proxy( $url ) ) {
			return false;
		}

		/**
		 * Filter whether to refuse a proxied request to a custom MT provider or webhook host.
		 *
		 * Asked only for a host name PerfLocale resolved (not an IP literal or a perflocale/mt/trusted_hosts entry).
		 *
		 * @hook  perflocale/http/refuse_proxied_custom_hosts
		 * @since 1.0.7.4
		 *
		 * @param bool   $refuse Whether to refuse. Default false: the proxy's own destination policy applies.
		 * @param string $url    Request URL.
		 * @return bool
		 */
		return (bool) apply_filters( 'perflocale/http/refuse_proxied_custom_hosts', false, $url );
	}

	/**
	 * Drop the cached DNS answers {@see validate_url()} keeps for a URL's host.
	 *
	 * @param string $url Provider URL.
	 * @return void
	 */
	private static function forget_dns( string $url ): void {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return;
		}

		delete_transient( 'perflocale_dns_' . \PerfLocale\Cache\CacheManager::flush_scoped_hash( $host ) );
		delete_transient( 'perflocale_dns6_' . \PerfLocale\Cache\CacheManager::flush_scoped_hash( $host ) );
	}

	/**
	 * A hostname's AAAA records, cached like the A lookup. Fails open (dns_get_record() is disabled on some hosts; the
	 * A check still applies). Mirrored in WebhookController: keep them in sync.
	 *
	 * @param string $host Hostname (never an IP literal - the caller returns first).
	 * @return array<int, string> IPv6 literals; empty when there are none or the
	 *   lookup is unavailable.
	 */
	private static function resolve_aaaa( string $host ): array {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return [];
		}

		$cache_key = 'perflocale_dns6_' . \PerfLocale\Cache\CacheManager::flush_scoped_hash( $host );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record() warns on a temporary resolver failure; the is_array() test below handles it and this lookup fails open by design.
		$records   = @dns_get_record( $host, DNS_AAAA );
		$addresses = [];

		foreach ( ( is_array( $records ) ? $records : [] ) as $record ) {
			$ipv6 = is_array( $record ) ? (string) ( $record['ipv6'] ?? '' ) : '';

			if ( $ipv6 !== '' ) {
				$addresses[] = $ipv6;
			}
		}

		set_transient( $cache_key, $addresses, 5 * MINUTE_IN_SECONDS );

		return $addresses;
	}

	/**
	 * Replace credential-shaped runs (24+ characters) in provider error text with "[REDACTED]" (Util\SecretMasker).
	 * Providers echo keys back, and the text is stored on job rows. Protected for providers outside make_request().
	 *
	 * @param string $body Truncated error body or upstream error message.
	 * @return string
	 */
	protected static function mask_credentials( string $body ): string {
		return \PerfLocale\Util\SecretMasker::mask( $body );
	}

	/**
	 * Error text with this provider's configured credential and credential-shaped runs masked.
	 *
	 * @param string $text Error text.
	 * @return string
	 */
	private function mask_error_text( string $text ): string {
		return self::mask_credentials( $this->mask_configured_credential( $text ) );
	}

	/**
	 * Replace this provider's configured credential, as sent, trimmed or URL-encoded, with `[REDACTED]`.
	 *
	 * A key of 1-3 characters only as a whole token, so the words around it stay readable; a longer one anywhere.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function mask_configured_credential( string $text ): string {
		$setting = self::CREDENTIAL_SETTING[ $this->get_id() ] ?? '';
		$secret  = '' === $setting ? '' : $this->settings->get( $setting, '' );
		$secret  = is_string( $secret ) ? $secret : '';
		$trimmed = trim( $secret );

		if ( '' === $trimmed ) {
			return $text;
		}

		$short = mb_strlen( $trimmed ) <= 3;
		$forms = array_unique( $short ? [ $trimmed, rawurlencode( $trimmed ) ] : [ $secret, $trimmed, rawurlencode( $trimmed ) ] );

		// Longest first, so a form that contains another is replaced whole.
		usort( $forms, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		if ( $short ) {
			$quoted = array_map( static fn( string $f ): string => preg_quote( $f, '/' ), $forms );
			$masked = preg_replace( '/(?<![\p{L}\p{N}_])(?:' . implode( '|', $quoted ) . ')(?![\p{L}\p{N}_])/u', '[REDACTED]', $text );

			// Text that is not UTF-8 fails the match: masked everywhere instead.
			if ( null !== $masked ) {
				return $masked;
			}
		}

		return str_replace( $forms, '[REDACTED]', $text );
	}

	/**
	 * Weekly `perflocale_mt_usage_gc` handler: delete `perflocale_mt_usage_YYYY_MM` options older than the current
	 * month and the 12 before it (all MtUsageView reads). Idempotent.
	 *
	 * @return int Number of options deleted.
	 */
	public static function gc_old_usage_counters(): int {
		global $wpdb;

		// Keep this month and the 12 before it.
		$cutoff = gmdate( 'Y_m', strtotime( '-12 months' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'perflocale_mt_usage_' ) . '%'
			)
		);

		$deleted = 0;
		foreach ( $names as $name ) {
			$name = (string) $name;
			// The YYYY_MM suffix of "perflocale_mt_usage_YYYY_MM".
			$suffix = substr( $name, strlen( 'perflocale_mt_usage_' ) );
			if ( $suffix !== '' && strcmp( $suffix, $cutoff ) < 0 ) {
				delete_option( $name );
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Track character usage.
	 *
	 * @param string $text Text that was translated.
	 * @return void
	 */
	protected function track_usage( string $text ): void {
		$this->track_usage_chars( mb_strlen( $text ) );
	}

	/**
	 * Add a batch's characters to the month's counter in one write.
	 *
	 * @param int $chars Total characters translated.
	 * @return void
	 */
	protected function track_usage_chars( int $chars ): void {
		if ( $chars <= 0 ) {
			return;
		}
		$this->session_usage += $chars;

		$month_key = 'perflocale_mt_usage_' . gmdate( 'Y_m' );

		global $wpdb;

		// One atomic upsert at the database: a get_option() read-modify-write undercounts under parallel runs and the cap
		// never engages; not add_option(), whose upsert resets a row another worker incremented.
		// Docs: docs-ai/11-code-rationale.md, "AbstractProvider: atomic usage counter".
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic counter upsert; the whole point is to bypass the object-cache RMW.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')
				ON DUPLICATE KEY UPDATE option_value = option_value + %d",
				$month_key,
				$chars,
				$chars
			)
		);

		// As add_option() does: drop the name from core's `notoptions` (else every get_option() answers 0 and the cap is
		// off) and the cached value, so the cap check reads the committed total.
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $month_key ] ) ) {
			unset( $notoptions[ $month_key ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
		wp_cache_delete( $month_key, 'options' );
	}

	/**
	 * Retry-After in seconds: delta-seconds or an HTTP date (RFC 7231 7.1.3), which an int cast would read as 0.
	 *
	 * @param mixed $header Raw header value (string or empty).
	 * @return int Seconds to wait, 0 if unparseable.
	 */
	private static function parse_retry_after( $header ): int {
		if ( ! is_string( $header ) || '' === $header ) {
			return 0;
		}

		$trimmed = trim( $header );

		// Delta-seconds.
		if ( Helper::is_ascii_digits( $trimmed ) ) {
			return (int) $trimmed;
		}

		// HTTP date.
		$ts = strtotime( $trimmed );
		if ( false === $ts ) {
			return 0;
		}

		$delta = $ts - time();
		return $delta > 0 ? $delta : 0;
	}
}
