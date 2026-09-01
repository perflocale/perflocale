<?php
/**
 * Abstract machine translation provider.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for machine translation providers.
 *
 * Provides common functionality: rate limiting, error handling,
 * retry with exponential backoff, and usage tracking.
 */
abstract class AbstractProvider implements ProviderInterface {

	/**
	 * Hostnames of public MT providers that never need SSRF validation.
	 *
	 * Skipping DNS resolution for these avoids the theoretical multi-
	 * second hang in `gethostbyname()` on hosts with a broken resolver
	 * (the function has no timeout parameter). These are all well-known
	 * public services; resolving them to private IPs would require an
	 * attacker to control the site's DNS anyway, at which point SSRF is
	 * not the weakest link.
	 *
	 * @var array<int, string>
	 */
	private const TRUSTED_HOSTS = [
		'translation.googleapis.com',
		'api.deepl.com',
		'api-free.deepl.com',
		'api.cognitive.microsofttranslator.com',
		'libretranslate.com',
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
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Normalize a language code to a consistent lowercase dash-form.
	 *
	 * Accepts any common input shape ('en', 'EN', 'en_US', 'en-us', 'EN-US')
	 * and returns lowercased, dash-separated form (e.g. 'en-us' or 'en').
	 *
	 * Providers that need specific formats (e.g. base-only codes, or
	 * provider-specific variant defaults) should call this first, then
	 * apply provider-specific rules on top.
	 *
	 * Fixes a class of bugs where WordPress locale strings like 'en_US' /
	 * 'de_DE' were passed straight to an MT API that expected 'en' / 'de'
	 * or 'en-US' / 'de-DE' and returned HTTP 400 "unsupported language".
	 *
	 * @param string $code Input language code.
	 * @return string Lowercased, dash-separated code.
	 */
	protected function normalize_language_code( string $code ): string {
		return strtolower( str_replace( '_', '-', trim( $code ) ) );
	}

	/**
	 * Reduce a language code to its base ISO 639-1 form.
	 *
	 * Used by providers that only accept base codes (no variants),
	 * e.g. LibreTranslate, DeepL source_lang, Google source.
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
	 * @param string $format Destination format hint: 'html' (default) or
	 *   'text'. In HTML mode the MT APIs entity-escape markup-significant
	 *   characters in their output (&amp;, &#39;), which is wrong for
	 *   plain-text destinations like post titles — those callers pass
	 *   'text'. The parameter is deliberately NOT on ProviderInterface:
	 *   adding it there would fatal third-party implementations, whereas
	 *   an extra positional arg to an implementation without it is simply
	 *   ignored by PHP (the provider stays in its historical mode).
	 */
	public function translate_batch( array $texts, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): array {
		$results = [];

		foreach ( $texts as $text ) {
			$results[] = $this->translate( $text, $source_lang, $target_lang, $fast_fail, $format );
		}

		return $results;
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
	 */
	protected function make_request( string $url, array $args, int $retries = 3, bool $fast_fail = false ): array {
		// SSRF protection: block requests to internal/private networks.
		$this->validate_url( $url );

		// Circuit breaker: if a recent spate of failures tripped the
		// breaker for this provider, throw immediately so callers can
		// route to a fallback (TM cache, "service unavailable" UI, etc.)
		// without burning 3 retries × 7-30s on a known-bad endpoint.
		// `mt_{provider_id}` is the canonical breaker key — matches what
		// Site Health card surfaces and what the global classify_error()
		// path in WpAiClientProvider feeds into.
		$breaker_key = 'mt_' . $this->get_id();

		if ( \PerfLocale\Concurrency\Breaker::is_open( $breaker_key ) ) {
			$status = \PerfLocale\Concurrency\Breaker::status( $breaker_key );
			// $breaker_key is built from a sanitize_key()-passed provider
			// slug; safe to pass through. esc_html() here is defence in
			// depth for the static-analyser's "all string args to throw
			// must be escaped" rule.
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

		// Enforce SSL verification to prevent MITM attacks.
		if ( ! isset( $args['sslverify'] ) ) {
			$args['sslverify'] = true;
		}

		// SSRF defense-in-depth: do not follow redirects. validate_url() above
		// already rejects localhost / private / cloud-metadata destinations,
		// but a validated public host could still 30x the request into one of
		// those. MT REST APIs reply directly and never need a redirect. (We do
		// NOT set reject_unsafe_urls here: it rejects non-80/443/8080 ports,
		// which would break a self-hosted LibreTranslate on e.g. :5000.) Set
		// if-absent so the request_args filter can re-enable for an odd endpoint.
		if ( ! isset( $args['redirection'] ) ) {
			$args['redirection'] = 0;
		}

		/**
		 * Filter the wp_remote_request() arguments before they are sent to a
		 * machine-translation provider. Integrators behind corporate proxies,
		 * or needing a longer timeout for bulk jobs, or injecting custom
		 * tracing headers should hook here.
		 *
		 * SSL verification stays forced-on unless the caller explicitly sets
		 * `sslverify => false` - PerfLocale does not override a false value.
		 *
		 * @hook perflocale/mt/request_args
		 *
		 * @param array $args wp_remote_request() argument array.
		 * @param string $url Destination URL (already SSRF-validated).
		 * @param string $provider_id Provider slug (e.g. 'deepl', 'google').
		 */
		$args = (array) apply_filters( 'perflocale/mt/request_args', $args, $url, $this->get_id() );

		$last_error     = null;
		$failure_reason = '';

		for ( $attempt = 1; $attempt <= $retries; $attempt++ ) {
			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$last_error     = $response->get_error_message();
				$failure_reason = 'transient';

				if ( $attempt < $retries ) {
					// Exponential backoff: 1s, 2s, 4s.
					sleep( (int) pow( 2, $attempt - 1 ) );
					continue;
				}

				break;
			}

			$code = wp_remote_retrieve_response_code( $response );

			// Rate limit hit - wait and retry. Cap the upstream Retry-After
			// at a sensible ceiling so a malicious / buggy provider returning
			// e.g. `Retry-After: 86400` cannot block this PHP worker for ~24h
			// per retry attempt. 300s default — matches what real providers
			// (DeepL, OpenAI, Azure) advise during sustained overload, so
			// legitimate long-backoff hints aren't truncated into a quick
			// retry that re-trips the breaker. Filterable.
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
				sleep( $wait );
				$failure_reason = 'rate_limit';
				continue;
			}

			if ( $code >= 200 && $code < 300 ) {
				// Success — clear any accumulated breaker counter so a
				// short hiccup doesn't take us halfway to OPEN forever.
				\PerfLocale\Concurrency\Breaker::record_success( $breaker_key );

				$body = wp_remote_retrieve_body( $response );

				return [
					'code' => $code,
					'body' => $body,
				];
			}

			// Truncate error body to avoid leaking API keys or sensitive data
			// in error messages that may reach the client or logs.
			$error_body = mb_substr( wp_remote_retrieve_body( $response ), 0, 200 );
			$error_body = self::mask_credentials( $error_body );
			$last_error = sprintf( 'HTTP %d: %s', $code, $error_body );

			// Categorise so the breaker uses an appropriate trip
			// threshold: auth errors (401/403) trip on the FIRST hit
			// because no amount of retries will fix a bad key; rate
			// limits and 5xx use the default sliding-window threshold.
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
				// Client error - don't retry.
				break;
			}

			// 5xx: back off with the same 1s/2s schedule as the transport-
			// error branch. Retrying immediately would hammer an endpoint
			// that just said it is overloaded and burn every attempt inside
			// an outage blip the backoff is meant to outlast.
			if ( $code >= 500 && $attempt < $retries ) {
				sleep( (int) pow( 2, $attempt - 1 ) );
			}
		}

		// All retries exhausted — record one failure with the most
		// telling category we saw. Auth errors get a one-shot trip so a
		// rotated/revoked API key takes the provider OUT immediately
		// instead of after 5 more retries × 7-30s each.
		// Only provider-health signals count toward the breaker: auth (revoked
		// key), rate_limit (429), transient (5xx / network). A per-request 4xx
		// such as 400 "unsupported language pair" is specific to that request —
		// recording it would let a batch of unsupported-language calls trip the
		// breaker and take the whole provider offline for unrelated, valid
		// requests. The exception is still thrown either way so the caller
		// learns this particular translation failed.
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
	 * Parse and validate a JSON response body.
	 *
	 * Some providers (Microsoft Translator) return a numerically-indexed
	 * top-level JSON array, others (DeepL, Google) return a string-keyed
	 * object. The decoded array could therefore use int OR string keys.
	 *
	 * @param string $body Raw response body.
	 * @return array<int|string, mixed> Decoded JSON data.
	 *
	 * @throws \RuntimeException If JSON is invalid.
	 */
	protected function parse_json_response( string $body ): array {
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
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
			throw new \RuntimeException(
				esc_html( sprintf( 'Unexpected response format from %s.', $this->get_name() ) )
			);
		}

		return $data;
	}

	/**
	 * Validate a URL to prevent SSRF attacks.
	 *
	 * Blocks requests to localhost, private IP ranges, and cloud metadata endpoints.
	 *
	 * @param string $url URL to validate.
	 * @return void
	 *
	 * @throws \RuntimeException If URL targets an internal network.
	 */
	private function validate_url( string $url ): void {
		$parsed = wp_parse_url( $url );
		$host   = $parsed['host'] ?? '';

		if ( $host === '' ) {
			throw new \RuntimeException( 'Invalid provider URL: missing host.' );
		}

		$host_lc = strtolower( $host );

		// Block localhost and loopback.
		$blocked_hosts = [ 'localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]' ];

		if ( in_array( $host_lc, $blocked_hosts, true ) ) {
			throw new \RuntimeException( 'Provider URL cannot target localhost.' );
		}

		// Fast path: known public MT providers skip the slow gethostbyname()
		// step. Their hostnames are operator-controlled (wp-config / settings),
		// so a private-IP resolution would already need a DNS hijack. The
		// hard-coded localhost/loopback check above ALWAYS runs and is not
		// filterable; everything below this point — the IPv4/IPv6 private-range
		// gates and the DNS resolution — is skipped for a listed entry. Be
		// precise about that when reading it as a security boundary: a match
		// here returns, so an IP LITERAL added to the list is not re-checked
		// against the private-range gates either. That is deliberate (it is the
		// only way to point the plugin at a self-hosted provider on a LAN), but
		// it means the list is a full bypass, not a fast path with a safety net.
		/**
		 * Filter the hostnames that skip DNS validation in the SSRF check.
		 *
		 * Use this to add custom MT provider hosts (e.g. when registering
		 * an OpenAI- or Anthropic- backed translator add-on) so their
		 * outbound calls don't hit the slow `gethostbyname()` path, or to
		 * reach a self-hosted provider (LibreTranslate) on an internal
		 * address the private-IP gate would otherwise refuse.
		 *
		 * Treat an entry here as a full SSRF exemption for that host: the
		 * private-IP and DNS gates do NOT run for it, whether it is a
		 * hostname or an IP literal. Only the hard-coded localhost/loopback
		 * literals stay blocked. Add only hosts you control.
		 *
		 * @hook perflocale/mt/trusted_hosts
		 *
		 * @param array<int, string> $hosts Lowercase trusted hostnames.
		 */
		$trusted = (array) apply_filters( 'perflocale/mt/trusted_hosts', self::TRUSTED_HOSTS );
		$trusted = array_map( 'strtolower', array_filter( $trusted, 'is_string' ) );

		if ( in_array( $host_lc, $trusted, true ) ) {
			return;
		}

		// wp_parse_url() returns IPv6 hosts wrapped in brackets per RFC 3986
		// (e.g. "[fc00::1]"). Strip them before passing to filter_var() /
		// inet_pton(), which want the bare literal.
		$host_ip = ( strlen( $host ) >= 2 && $host[0] === '[' && $host[-1] === ']' )
			? substr( $host, 1, -1 )
			: $host;

		// Unwrap IPv4-mapped IPv6 (::ffff:0:0/96) to the IPv4 it carries, so
		// the checks below judge the address the socket will actually reach.
		//
		// This is not theoretical tidying. PHP's FILTER_FLAG_NO_PRIV_RANGE /
		// NO_RES_RANGE reserved-range table has no entry for ::ffff:0:0/96 —
		// verified: ::1, ::, 2001:db8::, fc00:: and fe80:: are all rejected by
		// those flags, while ::ffff:127.0.0.1, ::ffff:10.0.0.1 and
		// ::ffff:169.254.169.254 all PASS them. The fc00::/7 + fe80::/10 byte
		// checks below don't catch them either (a mapped address starts with
		// ten zero bytes). And make_request() deliberately does not set
		// reject_unsafe_urls, so this method is the only gate: before this
		// unwrap, http://[::ffff:127.0.0.1]:PORT/ was validated, requested, and
		// answered by a loopback listener, while the plain http://127.0.0.1
		// form was correctly refused.
		//
		// Unwrapping rather than blanket-rejecting keeps the rule identical for
		// both spellings of the same address: a mapped PUBLIC IPv4 still passes
		// exactly as the bare form does.
		// Gated on FILTER_FLAG_IPV6 first so inet_pton() only ever sees a
		// literal it can parse — same shape as the fc00::/fe80:: byte check
		// further down.
		if ( filter_var( $host_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) !== false ) {
			$host_bin = inet_pton( $host_ip );

			if ( false !== $host_bin && 16 === strlen( $host_bin ) && "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" === substr( $host_bin, 0, 12 ) ) {
				$unwrapped = inet_ntop( substr( $host_bin, 12 ) );

				if ( false !== $unwrapped ) {
					$host_ip = $unwrapped;
				}
			}
		}

		// If the host is already an IP literal, skip DNS entirely - this is
		// both faster and avoids a potential hang on misconfigured resolvers.
		if ( filter_var( $host_ip, FILTER_VALIDATE_IP ) !== false ) {
			// FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE only cover IPv4.
			if ( filter_var( $host_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
				throw new \RuntimeException( 'Provider URL targets a private or reserved IP address.' );
			}

			// IPv6 equivalents: unique-local (fc00::/7) and link-local
			// (fe80::/10). Both are "private" in the SSRF sense but pass
			// PHP's IPv4-only private-range flag. Mirrors the byte check
			// in WebhookController::is_url_safe().
			if ( filter_var( $host_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) !== false ) {
				$bin = inet_pton( $host_ip );

				if ( $bin !== false && strlen( $bin ) === 16 ) {
					$first  = ord( $bin[0] );
					$second = ord( $bin[1] );

					// fc00::/7 - first 7 bits = 1111110 (0xFC or 0xFD).
					if ( ( $first & 0xFE ) === 0xFC ) {
						throw new \RuntimeException( 'Provider URL targets an IPv6 unique-local address.' );
					}

					// fe80::/10 - first 10 bits = 1111111010.
					if ( $first === 0xFE && ( $second & 0xC0 ) === 0x80 ) {
						throw new \RuntimeException( 'Provider URL targets an IPv6 link-local address.' );
					}
				}
			}

			return;
		}

		// Cache DNS lookups for a few minutes. gethostbyname() has no timeout
		// parameter - on a host with a broken /etc/resolv.conf it can block
		// for the full system timeout (often 30s per nameserver). Caching
		// the resolution limits the blast radius to one slow request per
		// host per 5 minutes.
		$cache_key = 'perflocale_dns_' . md5( $host );
		$ip        = get_transient( $cache_key );

		if ( $ip === false ) {
			$ip = gethostbyname( $host );
			set_transient( $cache_key, $ip, 5 * MINUTE_IN_SECONDS );
		}

		// Fail closed, mirroring WebhookController::is_url_safe(). gethostbyname()
		// returns the input unchanged on resolution failure AND does not
		// canonicalise hex/octal IP literals (e.g. 0x7f000001, 0177.0.0.1) that
		// libcurl and the PHP streams transport DO parse and connect to the
		// encoded address. The old `if ( $ip !== $host )` gate treated those as
		// "fine" and let obfuscated internal IPs slip past the private-range
		// check — require a real resolution to a verifiable public IP instead.
		if ( $ip === $host || filter_var( $ip, FILTER_VALIDATE_IP ) === false ) {
			throw new \RuntimeException( 'Provider URL could not be resolved to a verifiable public IP.' );
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
			throw new \RuntimeException( 'Provider URL resolves to a private or reserved IP address.' );
		}

		// Loopback 127.0.0.0/8 isn't covered by NO_RES_RANGE — explicit check
		// matches WebhookController::is_url_safe().
		if ( str_starts_with( $ip, '127.' ) ) {
			throw new \RuntimeException( 'Provider URL resolves to a loopback address.' );
		}
	}

	/**
	 * Redact anything token-like from a provider error message.
	 *
	 * Provider error responses *shouldn't* echo the API key back, but they
	 * do: OpenAI's 401 body is literally `Incorrect API key provided:
	 * sk-…`, and a misbehaving proxy can echo an Authorization header. The
	 * text eventually reaches the admin UI via the RuntimeException thrown
	 * below — and, for a background job, is persisted on the job row that
	 * the Jobs page and the REST detail endpoint render. Masking long
	 * alphanumeric runs is a cheap defense in depth: anything that looks
	 * like a 24+ character credential gets replaced with "[REDACTED]".
	 * Legitimate error text (HTTP verbs, short words, status codes) is
	 * untouched.
	 *
	 * `protected` rather than private because providers that do NOT route
	 * through make_request() — {@see \PerfLocale\MachineTranslation\Provider\WpAiClientProvider},
	 * which is invoked through a PHP callback — have to apply the same
	 * masking to the upstream message themselves.
	 *
	 * @param string $body Truncated error body or upstream error message.
	 * @return string
	 */
	protected static function mask_credentials( string $body ): string {
		if ( $body === '' ) {
			return $body;
		}

		$masked = preg_replace( '/[A-Za-z0-9_\-]{24,}/', '[REDACTED]', $body );

		return is_string( $masked ) ? $masked : $body;
	}

	/**
	 * Cron handler: drop `perflocale_mt_usage_YYYY_MM` options that no
	 * longer fall inside the retention window. Counter rows accumulate at
	 * one per month per blog forever otherwise; after a few years the
	 * options table carries dozens of historical rows nobody reads. The
	 * MtUsageView admin UI only ever queries the current and previous
	 * 12 months, so retaining 13 is sufficient.
	 *
	 * Scheduled weekly from Bootstrap (`perflocale_mt_usage_gc` event,
	 * per-blog). Idempotent — safe to re-run.
	 *
	 * @return int Number of options deleted.
	 */
	public static function gc_old_usage_counters(): int {
		global $wpdb;

		// Retain current month + 12 prior months. Any earlier YYYY_MM key
		// is dropped.
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
			// Extract the YYYY_MM suffix. Format is always
			// "perflocale_mt_usage_YYYY_MM".
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
	 * Batch-safe version of track_usage - single option write for any number
	 * of characters. Call once per batch request with the total character
	 * count, instead of calling track_usage() N times inside a loop.
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

		// Increment ATOMICALLY at the database in ONE upsert, NOT via a
		// read-modify-write of get_option(). The old lock+get_option RMW was
		// wrong two ways under concurrent MT (bulk_translate /
		// bulk_string_translate runs in parallel under
		// distinct type-locks, plus REST processes): (1) a long-lived worker's
		// get_option reads its OWN last-written value from the per-process
		// object cache, never other processes' committed increments →
		// last-writer-wins even under a perfect lock; (2) the lock's
		// non-blocking fallback ran the increment unlocked. Both silently
		// undercounted the monthly char total by up to ~90%, so the overage cap
		// never engaged → real paid-API charges.
		//
		// INSERT … ON DUPLICATE KEY UPDATE option_value = option_value + N is
		// the whole operation under the row lock: the row is created with $chars
		// if absent, or has $chars ADDED if present. Note we can NOT use
		// add_option() to pre-create the row — modern WP's add_option issues
		// `ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)`, so a
		// concurrent add_option('0') would RESET a row another worker had
		// already incremented. autoload 'no' keeps it out of alloptions on every
		// supported WP version.
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

		// Drop the cached option so every process's next get_option (the cap
		// check in TranslationService::would_exceed_limit) re-reads the fresh
		// committed total instead of a stale per-process value.
		// The raw INSERT bypasses add_option(), so when this month's row is
		// being CREATED the option name is still listed in the persistent
		// 'notoptions' cache (a prior get_option() miss put it there) — and a
		// stale notoptions entry makes every later get_option() return the
		// default 0 REGARDLESS of the DB value, silently disabling the monthly
		// cap. Mirror core add_option()'s cache maintenance: drop the name from
		// notoptions, then drop the per-option value cache.
		// 'notoptions' / 'options' are WordPress CORE's own cache key and group,
		// intentionally unprefixed. Core records "this option does not exist"
		// here; after we create the counter option we must clear our key from
		// core's negative-lookup list or every later get_option() for it keeps
		// short-circuiting to false. Writing this under a perflocale_ key would
		// leave core's cache untouched and reintroduce a query per request.
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $month_key ] ) ) {
			unset( $notoptions[ $month_key ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
		wp_cache_delete( $month_key, 'options' );
	}

	/**
	 * Parse an HTTP `Retry-After` header into seconds.
	 *
	 * RFC 7231 §7.1.3 allows both an integer (delta-seconds) and an
	 * HTTP-date form ("Wed, 21 Oct 2026 07:28:00 GMT"). A naive int-cast
	 * collapses the date form to 0 — letting the request retry immediately
	 * after a 429, which is the opposite of what the upstream asked for.
	 *
	 * @param mixed $header Raw header value (string or empty).
	 * @return int Seconds to wait, 0 if unparseable.
	 */
	private static function parse_retry_after( $header ): int {
		if ( ! is_string( $header ) || '' === $header ) {
			return 0;
		}

		$trimmed = trim( $header );

		// Delta-seconds form — pure integer string.
		if ( ctype_digit( $trimmed ) ) {
			return (int) $trimmed;
		}

		// HTTP-date form — fall back to strtotime + diff against now.
		$ts = strtotime( $trimmed );
		if ( false === $ts ) {
			return 0;
		}

		$delta = $ts - time();
		return $delta > 0 ? $delta : 0;
	}
}
