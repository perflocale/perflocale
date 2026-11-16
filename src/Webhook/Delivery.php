<?php
/**
 * Webhook delivery: URL safety, the signed POST, retries and the failure log.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Webhook;

use PerfLocale\Background\BackgroundEvents;
use PerfLocale\Concurrency\Lock;
use PerfLocale\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/Dispatcher.php';

/**
 * Runs in the delivery cron events and the registration URL check; loaded by a literal require (outside the classmap).
 *
 * Design (SSRF checks, queue, failure log): docs-ai/11-code-rationale.md, "WebhookController: URL safety checks".
 *
 * @internal
 */
final class Delivery {

	/**
	 * Option key for the permanent failure log.
	 */
	public const FAILURES_KEY = 'perflocale_webhook_failures';

	/**
	 * Maximum delivery attempts before permanently logging a failure.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Seconds before each retry, indexed by attempt - 1; after MAX_ATTEMPTS the failure is logged.
	 */
	private const RETRY_DELAYS = [ 30, 300, 1800 ];

	/**
	 * Times one delivery may be deferred by an open circuit breaker before it is logged as failed.
	 *
	 * A deferral is not a failed attempt (no POST was made); this only bounds a receiver that never recovers.
	 */
	private const MAX_BREAKER_DEFERRALS = 5;

	/**
	 * SSRF guard: refuse URLs WordPress cannot deliver to and loopback, link-local or private targets.
	 *
	 * @param string                  $url        Raw URL.
	 * @param bool                    $filterable Run the url_safe filter; false only on the internal AAAA re-entry.
	 * @param string|null             $reason     Out: why the URL was refused; '' when it is safe.
	 * @param array<int, string>|null $addresses  Out: for a safe host name, the judged addresses delivery is pinned to.
	 * @param-out array<int, string> $addresses
	 * @return bool True if safe to deliver to.
	 */
	public static function is_url_safe( string $url, bool $filterable = true, ?string &$reason = null, ?array &$addresses = null ): bool {
		// Out-params, not a wider return type. Callers tell POLICY reasons (never deliver) from 'unresolvable'
		// (the resolver could not answer): delivery retries only the second.
		$reason    = '';
		$addresses = [];
		$judged    = [];
		$parts     = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			$reason = 'unparseable_url';

			return false;
		}

		// Only http(s): the transport refuses any other scheme, so registering one would only produce failures.
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			$reason = 'unsupported_scheme';

			return false;
		}

		// wp_http_validate_url()'s rules, including its same-host port exemption. Gated on $filterable: the AAAA
		// re-entry's bracketed URL would fail them and refuse every dual-stack host.
		if ( $filterable ) {
			// Credentials in the URL: core refuses them, and the GET route would echo them.
			if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				$reason = 'userinfo_in_url';

				return false;
			}

			if ( isset( $parts['port'] ) ) {
				$port = (int) $parts['port'];

				/**
				 * Core's port allowlist through core's own filter, so the two cannot drift apart.
				 *
				 * @see wp_http_validate_url()
				 */
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter name; reading it is the point.
				$allowed_ports = apply_filters( 'http_allowed_safe_ports', [ 80, 443, 8080 ], $parts['host'], $url );

				if ( ! is_array( $allowed_ports ) || ! in_array( $port, $allowed_ports, true ) ) {
					// Core's same-host exemption: the site's own host and port is allowed on any port.
					$home      = wp_parse_url( (string) get_option( 'home' ) );
					$same_host = is_array( $home )
						&& isset( $home['host'] )
						&& strtolower( (string) $home['host'] ) === strtolower( (string) $parts['host'] );

					if ( ! ( $same_host && isset( $home['port'] ) && (int) $home['port'] === $port ) ) {
						$reason = 'disallowed_port';

						return false;
					}
				}
			}
		}

		$host = strtolower( (string) $parts['host'] );
		$safe = true;

		if ( in_array( $host, [ 'localhost', '127.0.0.1', '::1', '[::1]' ], true ) ) {
			$safe = false;

			// Named here, or the tail would report it as refused by a site policy filter.
			$reason = 'loopback_address';
		}

		// wp_parse_url() keeps IPv6 brackets ("[fc00::1]"); the IP checks want the bare literal.
		$host_ip = ( strlen( $host ) >= 2 && $host[0] === '[' && $host[-1] === ']' )
			? substr( $host, 1, -1 )
			: $host;

		// Unwrap IPv4-mapped IPv6 (::ffff:0:0/96), not reject it: PHP's range flags miss it, and a mapped public IPv4
		// must pass like its bare form. Twin of AbstractProvider::validate_url(); keep them in sync.
		if ( Helper::is_ipv6( $host_ip ) ) {
			$host_bin = inet_pton( $host_ip );

			if ( false !== $host_bin && 16 === strlen( $host_bin ) && "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" === substr( $host_bin, 0, 12 ) ) {
				$unwrapped = inet_ntop( substr( $host_bin, 12 ) );

				if ( false !== $unwrapped ) {
					$host_ip = $unwrapped;
				}
			}
		}

		// IP literal: refuse loopback, link-local, private, shared, reserved and multicast ranges.
		$is_ip_literal = Helper::is_ip( $host_ip );

		if ( $is_ip_literal ) {
			// The fc00::/7 and fe80::/10 byte checks below are a second layer.
			if ( ! Helper::is_public_outbound_address( $host_ip ) ) {
				$safe   = false;
				$reason = 'private_address';
			}

			// IPv6 unique-local (fc00::/7) and link-local (fe80::/10).
			if ( Helper::is_ipv6( $host_ip ) ) {
				// A validated IPv6 literal: inet_pton() cannot warn here.
				$bin = inet_pton( $host_ip );

				if ( $bin !== false && strlen( $bin ) === 16 ) {
					$first  = ord( $bin[0] );
					$second = ord( $bin[1] );

					// fc00::/7 - first 7 bits = 1111110 (0xFC or 0xFD).
					if ( ( $first & 0xFE ) === 0xFC ) {
						$safe   = false;
						$reason = 'private_address';
					}

					// fe80::/10 - first 10 bits = 1111111010.
					if ( $first === 0xFE && ( $second & 0xC0 ) === 0x80 ) {
						$safe   = false;
						$reason = 'private_address';
					}
				}
			}
		}

		// Host name: judge the resolved IPv4 (gethostbyname(), as core does) and every AAAA answer by the same ranges.
		if ( $safe && ! $is_ip_literal ) {
			$resolved = gethostbyname( $host );

			if ( $resolved === $host || ! Helper::is_ip( $resolved ) ) {
				// Unresolved (the input comes back): fail closed. The one refusal that says nothing about the URL,
				// so delivery retries it.
				$reason = 'unresolvable';
				$safe   = false;
			} elseif ( ! Helper::is_public_outbound_address( $resolved ) ) {
				// Private, shared, reserved or multicast IPv4.
				$reason = 'private_address';
				$safe   = false;
			} elseif ( str_starts_with( $resolved, '127.' ) ) {
				// Loopback 127.0.0.0/8, a second layer.
				$reason = 'loopback_address';
				$safe   = false;
			}

			// A dual-stack host may deliver over IPv6: each AAAA answer re-enters this method (one level deep,
			// unfiltered). Twin of AbstractProvider::validate_url(); keep in sync.
			$aaaa = $safe ? self::resolve_aaaa( $host ) : [];

			foreach ( $aaaa as $ipv6 ) {
				// Carry the inner reason out, or the tail reports a site policy refusal.
				$ipv6_reason = '';

				if ( ! self::is_url_safe( 'https://[' . $ipv6 . ']/', false, $ipv6_reason ) ) {
					$safe   = false;
					$reason = '' !== $ipv6_reason ? $ipv6_reason : 'private_address';
					break;
				}
			}

			// Every answer passed: delivery is pinned to these, not to a second lookup by the transport.
			if ( $safe ) {
				$judged = array_merge( [ $resolved ], $aaaa );
			}
		}

		if ( ! $filterable ) {
			return $safe;
		}

		/**
		 * Filter whether a webhook URL is considered safe to deliver to.
		 *
		 * True overrides the SSRF guard (internal delivery); use with caution.
		 *
		 * @hook perflocale/webhooks/url_safe
		 * @param bool $safe Default safety decision.
		 * @param string $url Raw URL being evaluated.
		 */
		$filtered = (bool) apply_filters( 'perflocale/webhooks/url_safe', $safe, $url );

		if ( $filtered ) {
			$reason = '';

			// A URL only the filter accepted has no judged address and uses the transport's own lookup.
			$addresses = $safe ? $judged : [];
		} elseif ( $reason === '' ) {
			// Every check above names its reason, so only a site filter's refusal reaches here.
			$reason = 'rejected';
		}

		return $filtered;
	}

	/**
	 * Resolve a hostname's AAAA records, cached for 5 minutes.
	 *
	 * Fails open without `dns_get_record` (some hosts disable it); the A check still applies. Twin of
	 * AbstractProvider::resolve_aaaa(); keep in sync.
	 *
	 * @param string $host Hostname (never an IP literal).
	 * @return array<int, string> IPv6 literals; empty when there are none or the lookup is unavailable.
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
	 * Deliver up to DRAIN_BATCH queued deliveries; reschedules itself while the queue is non-empty.
	 *
	 * @return void
	 */
	public static function drain(): void {
		// Claim the batch under the lock, deliver outside it (blocking POSTs). No lock: nothing claimed, a later tick runs.
		$batch = [];
		$more  = false;

		$claimed = Dispatcher::run_under_queue_lock(
			static function ( array $queue ) use ( &$batch, &$more ): array {
				if ( $queue === [] ) {
					return [];
				}

				$batch = array_splice( $queue, 0, Dispatcher::DRAIN_BATCH );
				$more  = $queue !== [];

				return $queue;
			}
		);

		// A refused claim still delivers: the write may have committed, and a repeat keeps the same delivery ids.
		if ( null === $claimed || $batch === [] ) {
			return;
		}

		// After a fatal mid-batch, shutdown re-queues the unsent tail (same delivery ids, at-least-once).
		$pending  = $batch;
		$reappend = static function () use ( &$pending ): void {
			if ( $pending === [] ) {
				return;
			}

			// To the front, preserving order.
			$requeued = Dispatcher::run_under_queue_lock(
				static function ( array $queue ) use ( $pending ): array {
					return array_merge( array_values( $pending ), $queue );
				}
			);

			if ( true === $requeued && ! wp_next_scheduled( Dispatcher::DRAIN_HOOK ) ) {
				BackgroundEvents::enqueue( Dispatcher::DRAIN_HOOK, [] );
			} elseif ( false === $requeued ) {
				Dispatcher::schedule_unqueued( $pending );
			}
		};
		register_shutdown_function( $reappend );

		foreach ( $batch as $i => $delivery ) {
			if ( is_array( $delivery ) && count( $delivery ) >= 4 ) {
				$webhook_id  = (string) ( $delivery[0] ?? '' );
				$event       = (string) ( $delivery[1] ?? '' );
				$payload     = (array) ( $delivery[2] ?? [] );
				$timestamp   = (string) ( $delivery[3] ?? '' );
				// The delivery id minted at enqueue; a 4-tuple gets one minted downstream.
				$delivery_id = (string) ( $delivery[4] ?? '' );
				self::deliver( $webhook_id, $event, $payload, $timestamp, 1, $delivery_id );
			}

			// Handled (delivered or retry scheduled): the shutdown handler skips it.
			unset( $pending[ $i ] );
		}

		$pending = []; // Whole batch handled - nothing for the shutdown handler.

		if ( $more && ! wp_next_scheduled( Dispatcher::DRAIN_HOOK ) ) {
			BackgroundEvents::enqueue( Dispatcher::DRAIN_HOOK, [] );
		}
	}

	/**
	 * Cron callback: POST the signed payload; retry a failure up to MAX_ATTEMPTS, then log it.
	 *
	 * @param string               $webhook_id Webhook UUID.
	 * @param string               $event Event name.
	 * @param array<string, mixed> $data Payload data.
	 * @param string               $timestamp ISO 8601 timestamp captured at enqueue.
	 * @param int                  $attempt Current attempt number (1-based).
	 * @param string               $delivery_id Stable delivery ID across attempts.
	 * @param int                  $breaker_deferrals Times an open breaker already deferred it.
	 * @return void
	 */
	public static function deliver( string $webhook_id, string $event, array $data, string $timestamp, int $attempt = 1, string $delivery_id = '', int $breaker_deferrals = 0 ): void {
		// One Delivery-ID across all attempts, for receiver dedup; an event without one gets it here.
		if ( '' === $delivery_id ) {
			$delivery_id = wp_generate_uuid4();
		}

		// During uninstall record_failure() would race the option deletion.
		if ( \PerfLocale\Plugin::is_uninstalling() ) {
			return;
		}

		// Whitelist again before signing: the cron argument is a stored array, and only whitelisted fields get signed.
		$data = Dispatcher::filter_payload( $event, $data );

		$webhooks = Events::stored();

		if ( ! isset( $webhooks[ $webhook_id ] ) ) {
			// Deleted webhook: discard without a failure entry.
			return;
		}

		$webhook = $webhooks[ $webhook_id ];

		// URL safety again at delivery (DNS or the filter may differ). 'unresolvable' retries like a 5xx; a policy
		// refusal is true of the URL itself, so it is logged once and dropped.
		$url           = (string) ( $webhook['url'] ?? '' );
		$safety_reason = '';
		$addresses     = [];

		if ( ! self::is_url_safe( $url, true, $safety_reason, $addresses ) ) {
			if ( $safety_reason === 'unresolvable' && $attempt < self::MAX_ATTEMPTS ) {
				\PerfLocale\Concurrency\Breaker::record_failure( 'webhook_' . $webhook_id, 'transient' );

				$base_delay = self::RETRY_DELAYS[ $attempt - 1 ] ?? 30;

				try {
					$jitter = random_int( 0, max( 1, (int) ( $base_delay / 2 ) ) );
				} catch ( \Throwable $e ) {
					$jitter = (int) ( $base_delay / 4 );
				}

				// No negative DNS cache, unlike the MT twin: it would outlive the retry delays and collapse the ladder
				// to one real try. Do not sync this part.
				$requeued = BackgroundEvents::enqueue(
					Dispatcher::RETRY_HOOK,
					[ $webhook_id, $event, $data, $timestamp, $attempt + 1, $delivery_id ],
					max( 1, $base_delay + $jitter )
				);

				if ( $requeued ) {
					return;
				}

				// The scheduler refused the retry: this is the last chance to leave a record.
				self::record_failure(
					$webhook_id,
					$event,
					$timestamp,
					'The webhook host could not be resolved, and a retry could not be scheduled; the delivery was dropped.'
				);

				return;
			}

			self::record_failure(
				$webhook_id,
				$event,
				$timestamp,
				$safety_reason === 'unresolvable'
					? 'The webhook host could not be resolved after the final attempt.'
					: sprintf( 'The webhook URL was refused at delivery time (%s).', $safety_reason )
			);

			return;
		}

		if ( $addresses !== [] && self::proxy_refuses( $url ) ) {
			self::record_failure( $webhook_id, $event, $timestamp, sprintf( 'The webhook URL was refused at delivery time (%s).', 'proxied' ) );

			return;
		}

		// Per-webhook breaker (not per URL), so one broken webhook does not pause another on the same host.
		$breaker_key = 'webhook_' . $webhook_id;

		if ( \PerfLocale\Concurrency\Breaker::is_open( $breaker_key ) ) {
			$status   = \PerfLocale\Concurrency\Breaker::status( $breaker_key );
			$cooldown = (int) ( $status['cooldown_remaining'] ?? 0 );

			// Defer past the cooldown, not drop: same attempt number, bounded by MAX_BREAKER_DEFERRALS.
			if ( $breaker_deferrals < self::MAX_BREAKER_DEFERRALS ) {
				try {
					$jitter = random_int( 5, 60 );
				} catch ( \Throwable $e ) {
					$jitter = 30;
				}

				// A refused enqueue goes to the failure log; deliberately no synchronous retry or busy wait.
				$deferred = BackgroundEvents::enqueue(
					Dispatcher::RETRY_HOOK,
					[ $webhook_id, $event, $data, $timestamp, $attempt, $delivery_id, $breaker_deferrals + 1 ],
					max( 30, $cooldown ) + $jitter
				);

				if ( ! $deferred ) {
					self::record_failure(
						$webhook_id,
						$event,
						$timestamp,
						'Retry could not be scheduled while the circuit breaker was open; the delivery was dropped.'
					);
				}

				return;
			}

			self::record_failure(
				$webhook_id,
				$event,
				$timestamp,
				sprintf(
					/* translators: %d: number of times delivery was deferred */
					'circuit_open (gave up after %d deferrals)',
					$breaker_deferrals
				)
			);
			return;
		}

		$payload = wp_json_encode(
			[
				'event'     => $event,
				'data'      => $data,
				'timestamp' => $timestamp,
			]
		);

		$headers = [
			'Content-Type'             => 'application/json',
			'X-PerfLocale-Event'       => $event,
			'X-PerfLocale-Delivery-ID' => $delivery_id,
			'X-PerfLocale-Attempt'     => (string) $attempt,
		];

		if ( ! empty( $webhook['secret'] ) ) {
			$headers['X-PerfLocale-Signature'] = 'sha256=' . hash_hmac(
				'sha256',
				(string) $payload,
				(string) $webhook['secret']
			);
		}

		// SSRF: no redirects (only the registered URL was judged), reject_unsafe_urls as a second layer, and the
		// connection pinned to the addresses is_url_safe() judged.
		$response = Helper::remote_request_pinned(
			$url,
			[
				'method'              => 'POST',
				'headers'             => $headers,
				'body'                => $payload,
				'timeout'             => 10,
				// Only the status is read: a hostile receiver must not stream a large body into the worker.
				'limit_response_size' => KB_IN_BYTES,
				'blocking'            => true,
				'sslverify'           => true,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
			],
			$addresses[0] ?? '',
			array_slice( $addresses, 1 )
		);

		$success = ! is_wp_error( $response )
			&& ( wp_remote_retrieve_response_code( $response ) >= 200 )
			&& ( wp_remote_retrieve_response_code( $response ) < 300 );

		if ( $success ) {
			\PerfLocale\Concurrency\Breaker::record_success( $breaker_key );
			self::clear_failure( $webhook_id );
			return;
		}

		$response_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		$error = is_wp_error( $response )
			? $response->get_error_message()
			: sprintf( 'HTTP %d', $response_code );

		// 401/403 is an auth problem no retry fixes: it trips the breaker at once. Others count toward its threshold.
		$failure_reason = match ( true ) {
			$response_code === 401 || $response_code === 403 => 'auth',
			$response_code === 429                            => 'rate_limit',
			$response_code >= 500                             => 'transient',
			is_wp_error( $response )                          => 'transient',
			default                                           => 'http_' . max( 0, $response_code ),
		};

		$threshold_override = $failure_reason === 'auth' ? 1 : 0;
		\PerfLocale\Concurrency\Breaker::record_failure( $breaker_key, $failure_reason, $threshold_override );

		if ( $attempt < self::MAX_ATTEMPTS ) {
			$base_delay = self::RETRY_DELAYS[ $attempt - 1 ] ?? 30;

			// Jitter [0, base/2], so retries against one recovering receiver do not arrive as a herd.
			try {
				$jitter = random_int( 0, max( 1, (int) ( $base_delay / 2 ) ) );
			} catch ( \Throwable $e ) {
				// random_int() throws without an entropy source.
				$jitter = (int) ( $base_delay / 4 );
			}

			$delay = max( 1, $base_delay + $jitter );

			$scheduled = BackgroundEvents::enqueue(
				Dispatcher::RETRY_HOOK,
				[ $webhook_id, $event, $data, $timestamp, $attempt + 1, $delivery_id ],
				$delay
			);

			// A refused retry means no further attempt: record the failure now.
			if ( ! $scheduled ) {
				self::record_failure(
					$webhook_id,
					$event,
					$timestamp,
					$error . ' (retry could not be scheduled; no further attempt will be made)'
				);
			}
		} else {
			self::record_failure( $webhook_id, $event, $timestamp, $error );
		}
	}

	/**
	 * Whether the site refuses to deliver to a resolved host through the HTTP proxy (the proxy resolves it itself).
	 *
	 * @param string $url Webhook URL.
	 * @return bool
	 */
	private static function proxy_refuses( string $url ): bool {
		$proxy = new \WP_HTTP_Proxy();

		if ( ! $proxy->is_enabled() || ! $proxy->send_through_proxy( $url ) ) {
			return false;
		}

		/** This filter is documented in src/MachineTranslation/AbstractProvider.php */
		return (bool) apply_filters( 'perflocale/http/refuse_proxied_custom_hosts', false, $url );
	}

	/**
	 * Append a delivery failure to the failure log (last 100 entries).
	 *
	 * @param string $webhook_id Webhook UUID.
	 * @param string $event Event name.
	 * @param string $timestamp Original event timestamp.
	 * @param string $error Error description.
	 * @return void
	 */
	private static function record_failure( string $webhook_id, string $event, string $timestamp, string $error ): void {
		// Site Health shows the log to every manage_options account: never add a receiver's response body to $error.
		// Only "HTTP NNN" skips the cleaning below.
		if ( preg_match( '/^HTTP \d{1,3}$/', $error ) !== 1 ) {
			// Control characters would break the rendered table.
			$error = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $error );

			// A pre_http_request filter or custom transport can fold the signing secret into its error message.
			$error = \PerfLocale\Util\SecretMasker::mask( $error );
		}

		// Locked, or two failures in one tick would lose an entry. A lock miss (null) is logged, never waited on.
		$logged = Lock::with(
			'webhook_failure_log',
			5,
			// True tells a completed section from a lock miss (null).
			function () use ( $webhook_id, $event, $timestamp, $error ): bool {
				self::forget_cached_log();
				$log = get_option( self::FAILURES_KEY, [] );

				if ( ! is_array( $log ) ) {
					$log = [];
				}

				$log[] = [
					'webhook_id' => $webhook_id,
					'event'      => $event,
					'timestamp'  => $timestamp,
					'error'      => mb_substr( $error, 0, 200 ),
					'failed_at'  => gmdate( 'Y-m-d H:i:s' ),
				];

				// Keep the last 100 entries.
				if ( count( $log ) > 100 ) {
					$log = array_slice( $log, -100 );
				}

				update_option( self::FAILURES_KEY, $log, false );

				return true;
			}
		);

		if ( null === $logged ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'PerfLocale WebhookController: webhook %s failed for event %s, but the failure log was locked and the entry was not recorded.',
					$webhook_id,
					$event
				)
			);
		}
	}

	/**
	 * Drop this request's cached copies of the failure log before a locked read (under the lock only).
	 *
	 * A request delivering several webhooks would otherwise overwrite another request's change with a stale copy.
	 *
	 * @return void
	 */
	private static function forget_cached_log(): void {
		wp_cache_delete( self::FAILURES_KEY, 'options' );
		Dispatcher::forget_listed_absence( self::FAILURES_KEY );
	}

	/**
	 * Create the failure log row as an empty list, autoload off, so later reads never meet `notoptions`.
	 *
	 * A direct SELECT confirms the row is absent first: add_option() would replace a log get_option() missed.
	 *
	 * @return void
	 */
	private static function seed_failure_log(): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- existence check that bypasses the options caches on purpose.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, self::FAILURES_KEY ) );

		if ( null !== $exists || '' !== $wpdb->last_error ) {
			return;
		}

		add_option( self::FAILURES_KEY, [], '', false );
	}

	/**
	 * Remove all failure log entries for a webhook that just succeeded.
	 *
	 * @param string $webhook_id Webhook UUID.
	 * @return void
	 */
	public static function clear_failure( string $webhook_id ): void {
		// A lock miss leaves stale failure rows until the next success: logged, so it is observable.
		$cleared = Lock::with(
			'webhook_failure_log',
			5,
			function () use ( $webhook_id ): bool {
				self::forget_cached_log();
				$log = get_option( self::FAILURES_KEY, null );

				if ( null === $log ) {
					self::seed_failure_log();

					return true;
				}

				if ( ! is_array( $log ) || empty( $log ) ) {
					return true;
				}

				$filtered = array_values(
					array_filter(
						$log,
						static function ( $entry ) use ( $webhook_id ): bool {
							return ( $entry['webhook_id'] ?? '' ) !== $webhook_id;
						}
					)
				);

				if ( count( $filtered ) !== count( $log ) ) {
					update_option( self::FAILURES_KEY, $filtered, false );
				}

				return true;
			}
		);
		if ( null === $cleared ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'PerfLocale WebhookController: webhook %s delivered successfully, but the failure log was locked so its stale failure rows remain until the next success.',
					$webhook_id
				)
			);
		}
	}
}
