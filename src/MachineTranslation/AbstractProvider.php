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
	 * Characters reserved under the monthly cap and not yet counted (reserve_usage()), with the month row they sit in.
	 *
	 * @var array{key: string, chars: int}
	 */
	private static array $reserved = [
		'key'   => '',
		'chars' => 0,
	];

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
		return $this->without_blank(
			$texts,
			function ( array $sent ) use ( $source_lang, $target_lang, $fast_fail, $format ): array {
				$results = [];

				foreach ( $sent as $i => $text ) {
					try {
						$results[] = $this->translate( $text, $source_lang, $target_lang, $fast_fail, $format );
					} catch ( \RuntimeException $e ) {
						throw ItemsFailedException::of( $e, $i, $i ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The provider's own exception, escaped where it was thrown.
					}
				}

				return $results;
			},
			$format
		);
	}

	/**
	 * Whether translate_batch() sends a list per request (false: one request per text).
	 *
	 * @since 1.0.7.5
	 * @return bool
	 */
	public function batches_natively(): bool {
		return ( new \ReflectionMethod( $this, 'translate_batch' ) )->getDeclaringClass()->getName() !== self::class;
	}

	/**
	 * Send only the texts with text ({@see BlockStructureGuard::has_text()}, attribute values in html); the rest come back
	 * unchanged and are not billed.
	 *
	 * @since 1.0.7.5
	 * @param array<int, string> $texts Texts.
	 * @param callable           $send   Translates a list of texts, answers in order.
	 * @param string             $format 'html' or 'text'.
	 * @phpstan-param callable(array<int, string>): array<int, string> $send
	 * @return array<int, string> Parallel to $texts.
	 * @throws \RuntimeException When $send answers a different number of texts.
	 * @throws ItemsFailedException With positions in $texts, when $send names failed texts.
	 */
	protected function without_blank( array $texts, callable $send, string $format = 'html' ): array {
		$texts = array_values( $texts );
		$at    = [];

		foreach ( $texts as $i => $text ) {
			if ( BlockStructureGuard::has_text( (string) $text, 'text' !== $format ) ) {
				$at[] = $i;
			}
		}

		if ( count( $at ) === count( $texts ) ) {
			return $send( $texts );
		}

		if ( [] === $at ) {
			return $texts;
		}

		try {
			$out = array_values( $send( array_map( static fn( int $i ): string => (string) $texts[ $i ], $at ) ) );
		} catch ( ItemsFailedException $e ) {
			throw new ItemsFailedException( $e->failure, $at[ $e->first ] ?? $e->first, $at[ $e->last ] ?? $e->last ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The provider's own exception, escaped where it was thrown.
		}

		// Merged by position: a different count would shift every later answer.
		if ( count( $out ) !== count( $at ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: 1: number of translations returned, 2: number sent */
						__( 'Translation provider returned %1$d entries for a batch of %2$d; aborting to avoid overwriting source text with empty strings.', 'perflocale' ),
						count( $out ),
						count( $at )
					)
				)
			);
		}

		foreach ( $at as $k => $i ) {
			$texts[ $i ] = (string) $out[ $k ];
		}

		return $texts;
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

		// The send guard_send() admitted is this request's; a later request (a poll) sends no text.
		$sending       = $this->sending;
		$this->sending = 0;

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

		$last_code      = 0;

		for ( $attempt = 1; $attempt <= $retries; $attempt++ ) {
			// A retry judges the host again (after a transport error the cached DNS answers are dropped below).
			if ( $attempt > 1 ) {
				$pinned_ip = $this->validate_url( $url );
			}

			// A retry is a new send: covered again under the monthly cap (a refusal ends the call here).
			if ( $attempt > 1 && $sending > 0 ) {
				self::reserve_send( $sending );
			}

			// Clamped after the filter, so no filtered timeout outlasts a slice or an interactive request.
			$call_args = $args;
			$timeout   = CallDeadline::timeout( $requested );

			if ( $timeout < $requested ) {
				$call_args['timeout'] = $timeout;
			}

			$response = Helper::remote_request_pinned( $url, $call_args, $pinned_ip );

			if ( is_wp_error( $response ) ) {
				$raw       = $response->get_error_message();
				$timed_out = 1 === preg_match( '/cURL error 28\b|timed out/i', $raw );

				// Only a failure to reach the host proves nothing was sent (resolve, connect, TLS handshake); any other
				// transport error may have been received and billed.
				if ( 1 !== preg_match( '/cURL error (6|7|35|51|58|60)\b/', $raw ) ) {
					self::bill_attempt( $sending );
				}

				// Cut by an interactive request's clamp: the post goes to a job (or fails named), the provider is not at fault.
				if ( $timed_out && $timeout < $requested && null === CallDeadline::window() ) {
					throw new DeadlineReachedException();
				}

				// Raw transport text can carry the request URL or headers: only its cURL error number is kept.
				$last_error     = self::transport_error( $raw, $timed_out ? $timeout : 0.0 );
				$last_code      = $timed_out ? 504 : 0;
				$failure_reason = 'transient';
				$this->log_error( 1 === preg_match( '/cURL error (\d+)/', $raw, $m ) ? 'transport error (cURL error ' . (int) $m[1] . ')' : 'transport error', $failure_reason );

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

			$code      = (int) wp_remote_retrieve_response_code( $response );
			$last_code = $code;

			// Received: a 2xx (usable or not) and a 5xx may be billed; a 4xx or 429 refusal is not.
			if ( ( $code >= 200 && $code < 300 ) || $code >= 500 ) {
				self::bill_attempt( $sending );
			}

			// 429 or 503 with a usable Retry-After: slept when it fits (at most 60 s), else a paced stop at the provider's
			// time, never a failure of the item and never a breaker record (a provider pacing us is working).
			if ( 429 === $code || 503 === $code ) {
				$retry_after = self::retry_after( wp_remote_retrieve_header( $response, 'retry-after' ), wp_remote_retrieve_header( $response, 'date' ) );

				if ( null !== $retry_after && $retry_after >= 1 ) {
					$wait = min( max( $retry_after, (int) pow( 2, 429 === $code ? $attempt : $attempt - 1 ) ), self::retry_after_max() );

					if ( CallDeadline::pause( $wait, $attempt < $retries && ! $fast_fail ) ) {
						// A provider's wait does not count toward the call's window.
						$started       += $wait;
						$failure_reason = 'rate_limit';
						continue;
					}

					\PerfLocale\Concurrency\Breaker::record_paced( $breaker_key );

					throw new DeadlineReachedException( time() + $wait, true, '', $retry_after ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Ints; the message is escaped in the class.
				}
			}

			// 429 without a usable Retry-After: today's backoff, counted on the breaker when it cannot be slept.
			if ( 429 === $code && $attempt < $retries ) {
				$wait = min( (int) pow( 2, $attempt ), self::retry_after_max() );

				if ( ! CallDeadline::wait( $wait ) ) {
					\PerfLocale\Concurrency\Breaker::record_failure( $breaker_key, 'rate_limit' );

					throw new DeadlineReachedException( time() + $wait, true, '', 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Ints; the message is escaped in the class.
				}

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

			// The body goes nowhere: a hostile endpoint can echo the sent text or secrets no mask knows.
			$last_error = self::http_error( (int) $code );

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

			$this->log_error( 'HTTP ' . (int) $code, $failure_reason );

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
			),
			$last_code // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int: the provider's HTTP status (504: timed out), read by REST callers.
		);
	}

	/**
	 * The REST status for a machine translation failure: the provider's refusal of the item as its own 4xx (400, 413,
	 * 422), a provider wait as 429, a timeout as 504, any other provider failure as 502; 500 for the site's own.
	 *
	 * @since 1.0.8
	 * @param \Throwable $e The failure.
	 * @return int
	 */
	public static function rest_status( \Throwable $e ): int {
		if ( $e instanceof DeadlineReachedException && $e->paced() ) {
			return 429;
		}

		$code = $e->getCode();

		return match ( true ) {
			! is_int( $code ) || $e instanceof NotSavedException => 500,
			in_array( $code, [ 400, 413, 422, 429, 504 ], true ) => $code,
			$code >= 400 && $code < 600 => 502,
			default => 500,
		};
	}

	/**
	 * The longest wait a provider's Retry-After is honoured for, in seconds (a job continues then).
	 *
	 * @return int 1 to 3600.
	 */
	protected static function retry_after_max(): int {
		/**
		 * Filter the longest provider wait (Retry-After) honoured, in seconds: a background job continues after it. At
		 * most 60 s are slept inside one request; a longer wait stops the job until then or hands the post to a job.
		 *
		 * @hook perflocale/mt/retry_after_max_seconds
		 * @param int $max_seconds Default 300; clamped to 1-3600.
		 */
		return self::clamp_wait( apply_filters( 'perflocale/mt/retry_after_max_seconds', 300 ) );
	}

	/**
	 * A filtered longest wait clamped to 1-3600 s; 300 for anything not a finite number.
	 *
	 * @param mixed $max Filter result.
	 * @return int
	 */
	private static function clamp_wait( mixed $max ): int {
		return is_numeric( $max ) && is_finite( (float) $max ) ? (int) max( 1, min( 3600, (float) $max ) ) : 300;
	}

	/**
	 * The shown text of a provider's HTTP error: its status and a fixed hint.
	 *
	 * @param int $code HTTP status.
	 * @return string
	 */
	private static function http_error( int $code ): string {
		$hint = match ( true ) {
			401 === $code || 403 === $code => __( 'The provider refused the key or access: check the key under PerfLocale → Settings.', 'perflocale' ),
			429 === $code => __( 'The provider is limiting requests: try again later.', 'perflocale' ),
			413 === $code => __( 'The request is larger than the provider accepts.', 'perflocale' ),
			$code >= 500 => __( 'The provider had a server error: try again later.', 'perflocale' ),
			default => __( 'The provider refused this request.', 'perflocale' ),
		};

		/* translators: 1: HTTP status, 2: what happened and what to do */
		return sprintf( __( 'HTTP %1$d. %2$s', 'perflocale' ), $code, $hint );
	}

	/**
	 * The shown text of a transport error: a timeout with its seconds, else its cURL error number, if any, and a hint.
	 *
	 * @param string $raw     Transport error message.
	 * @param float  $seconds The call's timeout when it timed out; 0 otherwise.
	 * @return string
	 */
	private static function transport_error( string $raw, float $seconds = 0.0 ): string {
		if ( $seconds > 0 ) {
			return self::timeout_error( $seconds );
		}

		if ( 1 === preg_match( '/cURL error (\d+)/', $raw, $m ) ) {
			/* translators: %d: cURL error number */
			return sprintf( __( 'Could not reach the provider (cURL error %d). Check the network or try again later.', 'perflocale' ), (int) $m[1] );
		}

		return __( 'Could not reach the provider. Check the network or try again later.', 'perflocale' );
	}

	/**
	 * The shown text of a call that timed out: the cause, its seconds and the next step.
	 *
	 * @param float $seconds The call's timeout.
	 * @return string
	 */
	public static function timeout_error( float $seconds ): string {
		$seconds = (int) max( 1, round( $seconds ) );

		return sprintf(
			/* translators: %d: seconds */
			_n( 'The provider did not answer within %d second (the request timed out). Try again later; a slow provider needs a longer timeout (perflocale/mt/request_args) or smaller parts (perflocale/mt/piece_chars).', 'The provider did not answer within %d seconds (the request timed out). Try again later; a slow provider needs a longer timeout (perflocale/mt/request_args) or smaller parts (perflocale/mt/piece_chars).', $seconds, 'perflocale' ),
			$seconds
		);
	}

	/**
	 * A failed call to the PHP error log, only with WP_DEBUG_LOG on: its status and category, never the provider's text.
	 *
	 * @since 1.0.7.5
	 * @param string $what     What failed, such as "HTTP 400".
	 * @param string $category Failure category, such as "auth".
	 * @return void
	 */
	protected function log_error( string $what, string $category ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Opt-in diagnosis (WP_DEBUG_LOG).
			error_log( sprintf( '[PerfLocale] %s %s: %s', $this->get_name(), $what, $category ) );
		}
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
		$data = $this->decode_json_response( $body );

		$this->accept_response();

		return $data;
	}

	/**
	 * Decode a JSON body; a body that is not JSON counts as a failure. The caller settles success once the content is
	 * usable ({@see accept_response()}, {@see reject_response()}).
	 *
	 * @since 1.0.7.5
	 * @param string $body Raw response body.
	 * @return array<int|string, mixed> Decoded JSON data.
	 * @throws \RuntimeException If JSON is invalid.
	 */
	protected function decode_json_response( string $body ): array {
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
	 * Whether every entry of an answer list is an array whose $key is a string.
	 *
	 * @since 1.0.7.5
	 * @param array<int|string, mixed> $entries Answer entries.
	 * @param string                   $key     Text key.
	 * @return bool
	 */
	protected static function all_text( array $entries, string $key ): bool {
		foreach ( $entries as $entry ) {
			if ( null === self::text_at( $entry, $key ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The string at a key path of a decoded answer; null when a step is missing or the value is not a string.
	 *
	 * @since 1.0.7.5
	 * @param mixed      $data    Decoded answer.
	 * @param int|string ...$path Keys, outermost first.
	 * @return string|null
	 */
	protected static function text_at( mixed $data, int|string ...$path ): ?string {
		foreach ( $path as $key ) {
			if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
				return null;
			}

			$data = $data[ $key ];
		}

		return is_string( $data ) ? $data : null;
	}

	/**
	 * Count the last 2xx answer as a breaker failure and throw: its content broke the provider's contract.
	 *
	 * @since 1.0.7.5
	 * @param string $message Escaped message.
	 * @return never
	 * @throws \RuntimeException Always.
	 */
	protected function refuse_response( string $message ): never {
		$this->reject_response( 'malformed' );

		throw new \RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
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

		$trusted = \PerfLocale\Util\OutboundUrl::mt_trusted_hosts();

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
		// Webhook\Delivery::is_url_safe() does; keep them in sync.
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

			// Unique-local fc00::/7 and link-local fe80::/10, again as a second layer (as Webhook\Delivery::is_url_safe()).
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

		// Fail closed (as Webhook\Delivery::is_url_safe()): gethostbyname() returns its input on failure and does not
		// canonicalise hex or octal literals, so a real public address is required.
		if ( ! is_string( $ip ) || $ip === $host || ! Helper::is_ip( $ip ) ) {
			throw new \RuntimeException( 'Provider URL could not be resolved to a verifiable public IP.' );
		}

		if ( ! Helper::is_public_outbound_address( $ip ) ) {
			throw new \RuntimeException( 'Provider URL resolves to a private or reserved IP address. To use a provider on an internal network, add its host to the perflocale/mt/trusted_hosts filter.' );
		}

		// Loopback 127.0.0.0/8, a second layer as in Webhook\Delivery::is_url_safe().
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
	 * A check still applies). Mirrored in Webhook\Delivery: keep them in sync.
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
		$this->track_usage_chars( \PerfLocale\Util\Utf8::length( $text ) );
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

		// The success counts its own send, which bill_attempt() had marked.
		self::$billed -= min( $chars, self::$billed );

		self::count_chars( $chars );
	}

	/**
	 * Add characters to the month's counter: from this call's reservation first, the rest in one atomic write.
	 *
	 * @param int $chars Characters.
	 * @return void
	 */
	private static function count_chars( int $chars ): void {
		$month_key = 'perflocale_mt_usage_' . gmdate( 'Y_m' );

		// Characters reserved for this call are in the counter already.
		if ( $month_key === self::$reserved['key'] ) {
			$counted                  = min( $chars, self::$reserved['chars'] );
			self::$reserved['chars'] -= $counted;
			$chars                   -= $counted;

			if ( 0 === $chars ) {
				return;
			}
		}

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

		self::forget_usage_cache( $month_key );
	}

	/**
	 * Characters the current request may still send after shortcode placeholders and filters ([ cap, left ]; cap 0 =
	 * none), set by with_send_cap().
	 *
	 * @var array{0: int, 1: int}
	 */
	private static array $send = [ 0, 0 ];

	/**
	 * Characters guard_send() reserved beyond the caller's reservation, returned by the next release_usage().
	 */
	private static int $guarded = 0;

	/**
	 * Characters of sends that reached the provider and may be billed but were not counted by a success; counted as used
	 * when the capped service call closes.
	 */
	private static int $billed = 0;

	/**
	 * Characters of this provider's current send (guard_send()), for its attempts.
	 */
	protected int $sending = 0;

	/**
	 * Monthly caps of the TranslationService calls in flight, innermost last (0 = no cap); empty outside one.
	 *
	 * @var list<int>
	 */
	private static array $caps = [];

	/**
	 * Open a service call under the monthly cap $limit (TranslationService::reserve_cap()); release_usage( ..., true )
	 * closes it.
	 *
	 * @param int $limit Monthly cap, 0 for none.
	 * @return void
	 */
	public static function open_cap( int $limit ): void {
		self::$caps[] = max( 0, $limit );
	}

	/**
	 * Run $fn with a cap on what its provider calls send in total, measured on the final provider-bound text.
	 *
	 * @template T
	 * @param int           $chars Characters (> 0).
	 * @param callable(): T $fn    The work.
	 * @return T What $fn returns.
	 */
	public static function with_send_cap( int $chars, callable $fn ): mixed {
		$was        = self::$send;
		self::$send = [ max( 1, $chars ), max( 1, $chars ) ];

		try {
			return $fn();
		} finally {
			self::$send = $was;
		}
	}

	/**
	 * Before a provider call, on the text it sends (after shortcode placeholders and text_before_send): refuse when it
	 * passes the request's cap or, for the part the caller did not reserve, the monthly cap. Nothing is sent then.
	 *
	 * @param int $chars Characters the call sends, counted as track_usage() counts them.
	 * @return void
	 * @throws \RuntimeException When a cap would be passed.
	 */
	protected function guard_send( int $chars ): void {
		$this->sending = $chars;

		if ( self::$send[0] > 0 ) {
			if ( $chars > self::$send[1] ) {
				throw new \RuntimeException(
					esc_html(
						sprintf(
							/* translators: 1: characters, 2: the request's limit */
							__( 'Machine translation refused: with shortcode placeholders and filters the text to send is %1$s characters, over this request\'s limit of %2$s. Translate less text at once.', 'perflocale' ),
							number_format_i18n( $chars ),
							number_format_i18n( self::$send[0] )
						)
					)
				);
			}

			self::$send[1] -= $chars;
		}

		self::reserve_send( $chars );
	}

	/**
	 * A send reached the provider: inside a service call it counts as used unless a success counts it.
	 *
	 * @param int $chars Characters of the send.
	 * @return void
	 */
	protected static function bill_attempt( int $chars ): void {
		if ( [] !== self::$caps ) {
			self::$billed += max( 0, $chars );
		}
	}

	/**
	 * Inside a capped service call, cover $chars more (plus the attempts already billed) from the reservation while it
	 * lasts, then by a new one; refuse when the monthly cap would be passed. A direct call (a connection test) has none.
	 *
	 * @param int $chars Characters about to be sent.
	 * @return void
	 * @throws \RuntimeException When the monthly cap would be passed.
	 */
	protected static function reserve_send( int $chars ): void {
		$limit = [] === self::$caps ? 0 : (int) end( self::$caps );
		$extra = $chars + self::$billed - ( 'perflocale_mt_usage_' . gmdate( 'Y_m' ) === self::$reserved['key'] ? self::$reserved['chars'] : 0 );

		if ( $limit <= 0 || $extra <= 0 ) {
			return;
		}

		if ( ! self::reserve_usage( $extra, $limit ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %1$s: Characters required, %2$s: Monthly limit */
						__( 'Monthly character limit would be exceeded (~%1$s characters required, limit %2$s). Translation blocked to prevent overage charges.', 'perflocale' ),
						number_format_i18n( $chars ),
						number_format_i18n( $limit )
					)
				)
			);
		}

		self::$guarded += $extra;
	}

	/**
	 * Reserve characters under the monthly cap before a provider call: one conditional UPDATE, so parallel runs cannot
	 * pass the cap together. The call's own count consumes the reservation; release_usage() returns the rest.
	 *
	 * @param int $chars Characters about to be sent.
	 * @param int $limit The monthly cap (> 0).
	 * @return bool False when the cap would be passed or a statement failed (nothing reserved).
	 */
	public static function reserve_usage( int $chars, int $limit ): bool {
		if ( $chars <= 0 ) {
			return true;
		}

		$wpdb      = \PerfLocale\Admin\Typed::wpdb();
		$month_key = 'perflocale_mt_usage_' . gmdate( 'Y_m' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The counter row, as track_usage_chars(); prepared just before.
		$row = $wpdb->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, 0, 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 0", $wpdb->options, $month_key );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $row is the prepare() result just above.
		$row = null === $row ? false : $wpdb->query( $row );
		$won = $wpdb->prepare( 'UPDATE %i SET option_value = option_value + %d WHERE option_name = %s AND option_value + %d <= %d', $wpdb->options, $chars, $month_key, $chars, $limit );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $won is the prepare() result just above.
		$won = false === $row || null === $won ? false : $wpdb->query( $won );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		self::forget_usage_cache( $month_key );

		if ( 1 !== $won ) {
			return false;
		}

		if ( $month_key !== self::$reserved['key'] ) {
			self::release_usage( PHP_INT_MAX );
			self::$reserved['key'] = $month_key;
		}

		self::$reserved['chars'] += $chars;

		return true;
	}

	/**
	 * Return the part of a reservation the provider call did not count (a failed call returns all of it).
	 *
	 * @param int  $chars The reservation's size.
	 * @param bool $close Also close the service call open_cap() opened.
	 * @return void
	 */
	public static function release_usage( int $chars, bool $close = false ): void {
		if ( $close ) {
			array_pop( self::$caps );

			// Sends that reached the provider without a success count as used, retries included.
			if ( self::$billed > 0 ) {
				$billed       = self::$billed;
				self::$billed = 0;
				self::count_chars( $billed );
			}
		}

		// guard_send()'s extra reservations go too: a successful call has counted them already.
		$want          = max( 0, $chars );
		$left          = min( $want > PHP_INT_MAX - self::$guarded ? PHP_INT_MAX : $want + self::$guarded, self::$reserved['chars'] );
		self::$guarded = 0;

		if ( $left <= 0 ) {
			return;
		}

		self::$reserved['chars'] -= $left;

		$wpdb = \PerfLocale\Admin\Typed::wpdb();

		$sql = $wpdb->prepare( 'UPDATE %i SET option_value = option_value - %d WHERE option_name = %s', $wpdb->options, $left, self::$reserved['key'] );

		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The counter row, as track_usage_chars(); prepared above.
			$wpdb->query( $sql );
		}

		self::forget_usage_cache( self::$reserved['key'] );
	}

	/**
	 * As add_option() does: drop the counter from core's `notoptions` (else every get_option() answers 0 and the cap is
	 * off) and its cached value, so the cap check reads the committed total.
	 *
	 * @param string $month_key The month's counter option.
	 * @return void
	 */
	private static function forget_usage_cache( string $month_key ): void {
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $month_key ] ) ) {
			unset( $notoptions[ $month_key ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
		wp_cache_delete( $month_key, 'options' );
	}

	/**
	 * Retry-After in seconds (RFC 9110 10.2.3): 1*DIGIT (more than 10 digits = PHP_INT_MAX), or an HTTP-date (IMF-fixdate,
	 * rfc850, asctime) taken against the response's own Date when it parses; null for anything else (never strtotime()).
	 *
	 * @param mixed $header Retry-After value; an array (repeated header) is null.
	 * @param mixed $date   The response's Date header.
	 * @return int|null Seconds, 0 for a past date; null when unusable.
	 */
	public static function retry_after( mixed $header, mixed $date = null ): ?int {
		if ( ! is_string( $header ) ) {
			return null;
		}

		$value = trim( $header );

		if ( '' === $value || strlen( $value ) > 64 ) {
			return null;
		}

		if ( 1 === preg_match( '/^[0-9]++$/D', $value ) ) {
			$digits = ltrim( $value, '0' );

			return strlen( $digits ) > 10 ? PHP_INT_MAX : (int) $digits;
		}

		$at = self::http_date( $value );

		if ( null === $at ) {
			return null;
		}

		$now = is_string( $date ) && strlen( $date ) <= 64 ? self::http_date( trim( $date ) ) : null;

		return max( 0, $at - ( $now ?? time() ) );
	}

	/**
	 * Unix time of an HTTP-date in one of the three forms; null for any other text or an impossible date.
	 *
	 * @param string $value Date text.
	 * @return int|null
	 */
	private static function http_date( string $value ): ?int {
		$value = (string) preg_replace( '/ {2,}+/', ' ', $value );
		$utc   = new \DateTimeZone( 'UTC' );

		foreach ( [ 'D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y' ] as $format ) {
			$parsed = \DateTimeImmutable::createFromFormat( '!' . $format, $value, $utc );
			$errors = \DateTimeImmutable::getLastErrors();

			// PHP 8.2+ answers false when there was nothing to report.
			if ( false === $parsed || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $parsed->format( $format ) !== $value ) {
				continue;
			}

			return $parsed->getTimestamp();
		}

		return null;
	}
}
