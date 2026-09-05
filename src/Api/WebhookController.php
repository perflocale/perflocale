<?php
/**
 * Webhook REST API controller.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Api;

use PerfLocale\Background\BackgroundEvents;
use PerfLocale\Concurrency\Lock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API endpoints for webhook management.
 *
 * Allows external tools to register webhook URLs that are called
 * when translation events occur (created, updated, content changed).
 *
 * POST /perflocale/v1/webhooks - Register a webhook.
 * GET /perflocale/v1/webhooks - List registered webhooks.
 * DELETE /perflocale/v1/webhooks/{id} - Remove a webhook.
 */
final class WebhookController extends RestController {

	/**
	 * REST base for this controller.
	 *
	 * @var string
	 */
	protected $rest_base = 'webhooks';

	/**
	 * Option key for stored webhooks.
	 */
	private const OPTION_KEY = 'perflocale_webhooks';

	/**
	 * Cron hook used for async webhook delivery (attempt 1).
	 */
	public const DELIVERY_HOOK = 'perflocale_deliver_webhook';

	/**
	 * Cron hook used for webhook delivery retries (attempts 2+).
	 */
	public const RETRY_HOOK = 'perflocale_retry_webhook';

	/**
	 * Cron hook that drains the coalesced delivery queue (WP-Cron engine).
	 */
	public const DRAIN_HOOK = 'perflocale_drain_webhooks';

	/**
	 * Non-autoloaded option holding queued deliveries awaiting the drainer.
	 */
	private const QUEUE_OPTION = 'perflocale_webhook_queue';

	/**
	 * Deliveries dispatched per drainer tick. Each is a blocking POST
	 * (10s timeout); bounded so one tick can't run for minutes, with an
	 * immediate reschedule while the queue is non-empty.
	 */
	private const DRAIN_BATCH = 20;

	/**
	 * Bounded retry for the (non-blocking) webhook_queue lock. The critical
	 * section is only ever a fast option write — never network I/O — so a
	 * contended acquire clears within a millisecond or two; retrying a handful
	 * of times reliably wins instead of dropping the caller's deliveries. The
	 * uncontended path acquires on the first try with no wait.
	 */
	private const QUEUE_LOCK_TRIES   = 40;
	private const QUEUE_LOCK_WAIT_US  = 5000;

	/**
	 * Hard ceiling on the coalesced queue. A healthy site drains DRAIN_BATCH
	 * per tick and stays tiny; a persistently broken cron is the only way to
	 * approach this. Above it, the OLDEST overflow is dropped (with a WP_DEBUG
	 * note) so the non-autoloaded option can't grow without bound.
	 */
	private const MAX_QUEUE = 10000;

	/**
	 * In-request delivery buffer, flushed once at shutdown into QUEUE_OPTION
	 * so a bulk operation firing thousands of events in one request costs
	 * ONE option write + ONE drainer event instead of N
	 * wp_schedule_single_event() calls (each of which rewrites the whole
	 * autoloaded `cron` option — quadratic, multi-MB).
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private static array $pending_buffer = [];

	/**
	 * Whether the shutdown flush is already registered this request.
	 *
	 * @var bool
	 */
	private static bool $flush_registered = false;

	/**
	 * Option key for the permanent failure log.
	 */
	private const FAILURES_KEY = 'perflocale_webhook_failures';

	/**
	 * Maximum delivery attempts before permanently logging a failure.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Seconds to wait before each retry attempt (indexed by attempt - 1).
	 *
	 * Attempt 1 → immediate (scheduled by fire_webhook).
	 * Attempt 2 → 30 s after first failure.
	 * Attempt 3 → 5 min after second failure.
	 * After attempt 3 → logged as permanent failure.
	 */
	private const RETRY_DELAYS = [ 30, 300, 1800 ];

	/**
	 * Times a single delivery may be re-enqueued because the per-webhook
	 * circuit breaker was open when it fired. A deferral is not a failed
	 * attempt (the POST was never made); this budget only bounds how long
	 * an event circulates against a receiver that never recovers —
	 * ~5 × (cooldown 300s + jitter) ≈ 30 min before dead-lettering.
	 */
	private const MAX_BREAKER_DEFERRALS = 5;

	/**
	 * Minimum acceptable HMAC secret length (characters).
	 *
	 * 32 chars of mixed entropy provides ~190 bits - safely above the
	 * HMAC-SHA256 threshold where brute-force becomes infeasible.
	 */
	private const MIN_SECRET_LEN = 32;

	/**
	 * Supported webhook event types.
	 */
	private const VALID_EVENTS = [
		'translation.created',
		'translation.updated',
		'content.changed',
	];

	/**
	 * Wire the webhook dispatcher to the plugin's translation/content hooks.
	 *
	 * Registered once from Bootstrap so every relevant plugin action
	 * automatically fans out to every subscribed webhook. Short-circuits
	 * cheaply when no webhooks are registered.
	 *
	 * @return void
	 */
	public static function register_event_dispatchers(): void {
		// Skip entirely if no webhooks are registered - avoids the cost of
		// hook execution on every translation action for sites that don't
		// use webhooks (which is most of them). Single-site only: on
		// multisite the current blog's option says nothing about the blogs
		// this request may switch_to_blog() into, so the listeners always
		// register and fire_webhook() no-ops per blog instead.
		if ( ! is_multisite() ) {
			$webhooks = get_option( self::OPTION_KEY, [] );

			if ( empty( $webhooks ) || ! is_array( $webhooks ) ) {
				return;
			}
		}

		add_action(
			'perflocale/translation/created',
			static function ( int $object_id, string $type, string $target_slug ): void {
				( new self() )->fire_webhook(
					'translation.created',
					[
						'object_id'     => $object_id,
						'object_type'   => $type,
						'language_slug' => $target_slug,
					]
				);
			},
			10,
			3
		);

		add_action(
			'perflocale/translation/linked',
			static function ( int $group_id, int $object_id, int $language_id ): void {
				( new self() )->fire_webhook(
					'translation.updated',
					[
						'group_id'    => $group_id,
						'object_id'   => $object_id,
						'language_id' => $language_id,
					]
				);
			},
			10,
			3
		);

		add_action(
			'perflocale/translation/status_changed',
			static function ( int $object_id, string $status, int $language_id ): void {
				( new self() )->fire_webhook(
					'translation.updated',
					[
						'object_id'   => $object_id,
						'status'      => $status,
						'language_id' => $language_id,
					]
				);
			},
			10,
			3
		);

		add_action(
			'perflocale/content/changed',
			static function ( int $object_id, string $type, int $group_id ): void {
				( new self() )->fire_webhook(
					'content.changed',
					[
						'object_id'   => $object_id,
						'object_type' => $type,
						'group_id'    => $group_id,
					]
				);
			},
			10,
			3
		);
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'register_webhook' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
					'args'                => [
						'url'    => [
							'required'          => true,
							'type'              => 'string',
							'validate_callback' => static function ( $value ): bool {
								return filter_var( $value, FILTER_VALIDATE_URL ) !== false;
							},
							'sanitize_callback' => 'esc_url_raw',
						],
						'events' => [
							'required'          => true,
							'type'              => 'array',
							'items'             => [ 'type' => 'string' ],
							'validate_callback' => static function ( $value ): bool {
								if ( ! is_array( $value ) || empty( $value ) ) {
									return false;
								}
								foreach ( $value as $event ) {
									if ( ! in_array( $event, self::VALID_EVENTS, true ) ) {
										return false;
									}
								}
								return true;
							},
						],
						'secret' => [
							'required' => false,
							'type'     => 'string',
							'default'  => '',
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_webhooks' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
				],
			]
		);

		// UUID v4 with hyphens: 8-4-4-4-12 hex characters.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-f0-9-]{32,36})',
			[
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_webhook' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
				],
			]
		);
	}

	/**
	 * Reject URLs that target loopback or link-local addresses.
	 *
	 * Webhooks are a classic SSRF surface - an admin (or a plugin that
	 * mistakenly accepts less-privileged input) could register a URL like
	 * http://127.0.0.1:6379/ or http://169.254.169.254/latest/meta-data/
	 * to probe internal services. Block the common cases here. Sites that
	 * genuinely need internal delivery can opt in via the filter.
	 *
	 * @param string $url        Raw URL.
	 * @param bool   $filterable Whether to run the `perflocale/webhooks/url_safe`
	 *   filter on the verdict. True for every caller-supplied URL; the AAAA
	 *   re-entry below passes false so a site's filter is never handed a
	 *   synthetic `https://[<ipv6>]/` URL nobody registered.
	 * @return bool True if safe to deliver to.
	 */
	private function is_url_safe( string $url, bool $filterable = true ): bool {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		// Only http(s) can ever deliver: WP's transport (wp_http_validate_url
		// via reject_unsafe_urls) rejects every other scheme at request time,
		// so accepting e.g. ftp:// at REGISTRATION would return 201 + secret
		// and then produce nothing but retry churn and failure-log noise.
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return false;
		}

		$host = strtolower( (string) $parts['host'] );
		$safe = true;

		if ( in_array( $host, [ 'localhost', '127.0.0.1', '::1', '[::1]' ], true ) ) {
			$safe = false;
		}

		// wp_parse_url() returns IPv6 hosts wrapped in brackets per RFC 3986
		// (e.g. "[fc00::1]"). Strip them before passing to filter_var() /
		// inet_pton(), which want the bare literal.
		$host_ip = ( strlen( $host ) >= 2 && $host[0] === '[' && $host[-1] === ']' )
			? substr( $host, 1, -1 )
			: $host;

		// Unwrap IPv4-mapped IPv6 (::ffff:0:0/96) to the IPv4 it carries, so the
		// checks below judge the address a socket would actually reach. PHP's
		// FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE tables have no entry for that
		// prefix — ::ffff:127.0.0.1, ::ffff:10.0.0.1 and ::ffff:169.254.169.254
		// all PASS them — and the fc00::/fe80:: byte checks below cannot catch
		// them either, because a mapped address begins with ten zero bytes.
		// Measured before this unwrap: is_url_safe( 'http://[::ffff:127.0.0.1]/h' )
		// returned true while the plain 'http://127.0.0.1/h' form was correctly
		// refused, so a webhook could be REGISTERED against loopback, cloud
		// metadata or any RFC1918 host.
		//
		// This mirrors {@see \PerfLocale\MachineTranslation\AbstractProvider::validate_url()}
		// exactly, and the two are meant to agree — that method's own comment
		// names this one as its counterpart. Keep them in sync.
		//
		// Unwrapping rather than blanket-rejecting keeps the rule identical for
		// both spellings of one address: a mapped PUBLIC IPv4 still passes just
		// as the bare form does. Gated on FILTER_FLAG_IPV6 first so inet_pton()
		// only ever sees a literal it can parse.
		if ( filter_var( $host_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) !== false ) {
			$host_bin = inet_pton( $host_ip );

			if ( false !== $host_bin && 16 === strlen( $host_bin ) && "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" === substr( $host_bin, 0, 12 ) ) {
				$unwrapped = inet_ntop( substr( $host_bin, 12 ) );

				if ( false !== $unwrapped ) {
					$host_ip = $unwrapped;
				}
			}
		}

		// IP literal - reject loopback, link-local, and RFC1918 ranges.
		$is_ip_literal = ( filter_var( $host_ip, FILTER_VALIDATE_IP ) !== false );

		if ( $is_ip_literal ) {
			// FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE only cover IPv4.
			if ( filter_var( $host_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
				$safe = false;
			}

			// IPv6 equivalents: unique-local (fc00::/7) and link-local
			// (fe80::/10). Both are "private" in the SSRF sense but pass
			// PHP's IPv4-only private-range flag.
			if ( filter_var( $host_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) !== false ) {
				// $host_ip was just validated as a well-formed IPv6 literal,
				// so inet_pton() can't emit a warning here - no @-suppression
				// needed. The is_string() guard is defensive in case PHP
				// returns false for some pathological edge.
				$bin = inet_pton( $host_ip );

				if ( $bin !== false && strlen( $bin ) === 16 ) {
					$first  = ord( $bin[0] );
					$second = ord( $bin[1] );

					// fc00::/7 - first 7 bits = 1111110 (0xFC or 0xFD).
					if ( ( $first & 0xFE ) === 0xFC ) {
						$safe = false;
					}

					// fe80::/10 - first 10 bits = 1111111010.
					if ( $first === 0xFE && ( $second & 0xC0 ) === 0x80 ) {
						$safe = false;
					}
				}
			}
		}

		// Hostname — resolve via DNS and re-check the IP against the same
		// private/reserved ranges. Catches the admin-mistake case (a domain
		// resolving to 169.254.169.254 metadata / 127.0.0.1 / an internal
		// service); full DNS-rebinding prevention (pinning the IP through the
		// transport) is out of scope. Uses gethostbyname() to mirror WP core's
		// wp_http_validate_url() and avoid warnings on failure — single-A-record
		// only; the perflocale/webhooks/url_safe filter can bridge fuller
		// coverage. Skipped for IP literals and once $safe is already false.
		if ( $safe && ! $is_ip_literal ) {
			$resolved = gethostbyname( $host );

			if ( $resolved === $host || ! filter_var( $resolved, FILTER_VALIDATE_IP ) ) {
				// gethostbyname() returns the input string unchanged on
				// resolution failure. Fail closed: if we can't resolve the
				// host now, refuse the URL rather than fail-open - a hostile
				// DNS server could otherwise return a public IP at
				// validation time and a private IP at delivery time.
				$safe = false;
			} elseif ( filter_var( $resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
				// Resolved to a private (RFC1918) or reserved IPv4 range.
				$safe = false;
			} elseif ( str_starts_with( $resolved, '127.' ) ) {
				// Loopback 127.0.0.0/8 - not covered by NO_RES_RANGE.
				$safe = false;
			}

			// AAAA blind spot. gethostbyname() is IPv4-only, so a hostname
			// publishing A=<public> alongside AAAA=::1 passes everything above
			// and a dual-stack box may still deliver over the IPv6 answer.
			// Re-enter this method with each AAAA literal rather than restating
			// the rules, so the IPv4-mapped unwrap and the fc00::/fe80:: byte
			// checks apply verbatim; $filterable is false so the public
			// url_safe filter is not handed a URL nobody registered. An IP
			// literal never reaches this branch, so the recursion is one level
			// deep. Mirrors AbstractProvider::validate_url(); keep in sync.
			foreach ( ( $safe ? self::resolve_aaaa( $host ) : [] ) as $ipv6 ) {
				if ( ! $this->is_url_safe( 'https://[' . $ipv6 . ']/', false ) ) {
					$safe = false;
					break;
				}
			}
		}

		if ( ! $filterable ) {
			return $safe;
		}

		/**
		 * Filter whether a webhook URL is considered safe to deliver to.
		 *
		 * Return false from this filter to block delivery, or true to
		 * override the default SSRF guard (use with caution).
		 *
		 * @hook perflocale/webhooks/url_safe
		 * @param bool $safe Default safety decision.
		 * @param string $url Raw URL being evaluated.
		 */
		return (bool) apply_filters( 'perflocale/webhooks/url_safe', $safe, $url );
	}

	/**
	 * Resolve a hostname's AAAA records, cached for 5 minutes.
	 *
	 * Fails OPEN when `dns_get_record` is unavailable (some managed hosts
	 * disable it): refusing every webhook there would break delivery on those
	 * sites to close a hole the A-record gate already covers in the ordinary
	 * case. Twin of
	 * {@see \PerfLocale\MachineTranslation\AbstractProvider::resolve_aaaa()};
	 * keep them in sync.
	 *
	 * @param string $host Hostname (never an IP literal — the caller checks first).
	 * @return array<int, string> IPv6 literals; empty when there are none or the
	 *   lookup is unavailable.
	 */
	private static function resolve_aaaa( string $host ): array {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return [];
		}

		$cache_key = 'perflocale_dns6_' . md5( $host );
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
	 * Verify the `X-PerfLocale-Signature` header against a secret.
	 *
	 * Consumers should call this on the receiving end:
	 *
	 * $ok = WebhookController::verify_signature(
	 * file_get_contents( 'php://input' ),
	 * $my_shared_secret,
	 * $_SERVER['HTTP_X_PERFLOCALE_SIGNATURE'] ?? ''
	 * );
	 *
	 * Uses hash_equals() for a constant-time comparison.
	 *
	 * @param string $payload Raw request body.
	 * @param string $secret Shared secret.
	 * @param string $signature Value of the X-PerfLocale-Signature header.
	 * @return bool True if the signature matches.
	 */
	public static function verify_signature( string $payload, string $secret, string $signature ): bool {
		if ( $secret === '' || $signature === '' ) {
			return false;
		}

		if ( ! str_starts_with( $signature, 'sha256=' ) ) {
			return false;
		}

		$provided = substr( $signature, 7 );
		$expected = hash_hmac( 'sha256', $payload, $secret );

		return hash_equals( $expected, $provided );
	}

	/**
	 * Register a new webhook.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function register_webhook( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$url    = $request->get_param( 'url' );
		$events = $request->get_param( 'events' );
		$secret = (string) $request->get_param( 'secret' );
		$secret = trim( $secret );

		if ( ! $this->is_url_safe( $url ) ) {
			return $this->error( 'unsafe_url', __( 'Webhook URL targets a loopback or private-range address.', 'perflocale' ) );
		}

		// Auto-generate a strong secret when one wasn't supplied - clients
		// that skipped the field still get HMAC signing by default.
		$secret_generated = false;

		if ( $secret === '' ) {
			$secret           = wp_generate_password( 48, true, true );
			$secret_generated = true;
		} elseif ( strlen( $secret ) < self::MIN_SECRET_LEN ) {
			return $this->error(
				'weak_secret',
				sprintf(
					/* translators: %d: Minimum secret length. */
					__( 'Webhook secret must be at least %d characters.', 'perflocale' ),
					self::MIN_SECRET_LEN
				)
			);
		}

		// Read-modify-write `wp_options` is racy under concurrent admin
		// requests: two near-simultaneous register_webhook calls would each
		// read the pre-write list, append their own row, and the second
		// write wins — losing the first registration silently. Serialize
		// the read+write through a short-lived lock.
		$id       = wp_generate_uuid4();
		$response = Lock::with(
			'webhooks_write',
			10,
			function () use ( $id, $url, $events, $secret ): array {
				// Force a fresh DB read inside the lock. Without a persistent
				// object cache this process may hold a stale options copy from
				// before a concurrent lock-holder's update_option(), and would
				// otherwise clobber that write.
				wp_cache_delete( self::OPTION_KEY, 'options' );
				$webhooks = $this->get_stored_webhooks();

				$webhooks[ $id ] = [
					'id'         => $id,
					'url'        => $url,
					'events'     => $events,
					'secret'     => $secret,
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				];

				// autoload=false: webhook delivery runs in cron/hook context where
				// the extra get_option() query is cheap, and HMAC secrets stored
				// here should not sit in `alloptions` on every page load.
				update_option( self::OPTION_KEY, $webhooks, false );

				return $webhooks[ $id ];
			}
		);

		if ( $response === null ) {
			return $this->error( 'lock_unavailable', __( 'Another webhook registration is in progress; please retry.', 'perflocale' ), 503 );
		}

		// The secret is only returned once, at registration - the caller
		// needs it to verify signatures on their end. Subsequent GET
		// /webhooks always strips it from responses.
		$response['warning'] = __( 'The secret is only returned once during registration. Store it securely - subsequent GET requests will NOT include it.', 'perflocale' );

		return $this->success( $response, 201 );
	}

	/**
	 * List all registered webhooks.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_webhooks( \WP_REST_Request $request ): \WP_REST_Response {
		$webhooks = $this->get_stored_webhooks();

		// Strip secrets from the response.
		$safe = array_map(
			static function ( array $hook ): array {
				unset( $hook['secret'] );
				return $hook;
			},
			array_values( $webhooks )
		);

		return $this->success( $safe );
	}

	/**
	 * Delete a registered webhook.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_webhook( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = sanitize_text_field( $request->get_param( 'id' ) );

		// Same race protection as register_webhook — concurrent deletes (or
		// a delete colliding with a register) would lose one operation.
		$result = Lock::with(
			'webhooks_write',
			10,
			function () use ( $id ): bool|string {
				// Fresh DB read inside the lock — see register_webhook().
				wp_cache_delete( self::OPTION_KEY, 'options' );
				$webhooks = $this->get_stored_webhooks();

				if ( ! isset( $webhooks[ $id ] ) ) {
					return 'not_found';
				}

				unset( $webhooks[ $id ] );
				// autoload=false: webhook delivery runs in cron/hook context where
				// the extra get_option() query is cheap, and HMAC secrets stored
				// here should not sit in `alloptions` on every page load.
				update_option( self::OPTION_KEY, $webhooks, false );

				return true;
			}
		);

		if ( $result === null ) {
			return $this->error( 'lock_unavailable', __( 'Another webhook write is in progress; please retry.', 'perflocale' ), 503 );
		}

		if ( $result === 'not_found' ) {
			return $this->error( 'not_found', __( 'Webhook not found.', 'perflocale' ), 404 );
		}

		// Clear any pending retries for the deleted webhook.
		$this->clear_failure( $id );

		return $this->success( [ 'deleted' => true ] );
	}

	/**
	 * Fire webhooks for a given event.
	 *
	 * Enqueues a single wp-cron event per subscriber so delivery runs on
	 * the next cron tick - the originating request returns immediately.
	 *
	 * @param string               $event Event name (e.g., 'translation.created').
	 * @param array<string, mixed> $data Event payload data.
	 * @return void
	 */
	public function fire_webhook( string $event, array $data ): void {
		// Staging/development clones carry production webhook URLs in their
		// cloned DB — fail closed so a clone never fires endpoints that
		// belong to the production site. Opt back in via the
		// perflocale/dispatch/allow_non_production filter.
		if ( ! \PerfLocale\Helper::is_outbound_dispatch_allowed( 'webhooks' ) ) {
			return;
		}

		$webhooks = $this->get_stored_webhooks();

		if ( empty( $webhooks ) ) {
			return;
		}

		// Whitelist payload keys per event so callers can't accidentally
		// leak user objects, post content, auth tokens, or other sensitive
		// context through a hook that happens to pass extra data along.
		$data = self::filter_payload( $event, $data );

		// On Action Scheduler each async action is its own table row, so
		// per-webhook enqueue is already cheap — keep the direct path. On
		// plain WP-Cron, every wp_schedule_single_event rewrites the single
		// autoloaded `cron` option, so a bulk fan-out is quadratic and
		// bloats a per-pageview option; coalesce those into one queue.
		$coalesce = ! BackgroundEvents::is_action_scheduler_engine();
		$ts       = gmdate( 'Y-m-d\TH:i:s\Z' );

		foreach ( $webhooks as $webhook ) {
			if ( ! in_array( $event, $webhook['events'], true ) ) {
				continue;
			}

			if ( $coalesce ) {
				// Mint the delivery id HERE, once, and carry it on the queue
				// entry so a redelivery (drain re-append after a mid-batch
				// fatal) keeps the SAME X-PerfLocale-Delivery-ID — otherwise a
				// fresh id per drain would defeat receiver-side dedup and turn
				// at-least-once into duplicate-delivery. The trailing blog id
				// records where the event fired: the buffer flushes at shutdown
				// AFTER any switch_to_blog() has been restored, so flush must
				// land each delivery in the queue of the blog whose webhook it
				// targets (webhooks + queue + cron are all per-blog).
				self::$pending_buffer[] = [ $webhook['id'], $event, $data, $ts, wp_generate_uuid4(), get_current_blog_id() ];
				continue;
			}

			// Action Scheduler path: each async action is its own row, and the
			// id is minted on first delivery + reused across retries downstream.
			BackgroundEvents::enqueue( self::DELIVERY_HOOK, [ $webhook['id'], $event, $data, $ts ] );
		}

		if ( self::$pending_buffer !== [] && ! self::$flush_registered ) {
			self::arm_shutdown_flush();
		}
	}

	/**
	 * Register the one-shot shutdown flush for the coalesced buffer.
	 *
	 * @return void
	 */
	private static function arm_shutdown_flush(): void {
		self::$flush_registered = true;
		add_action( 'shutdown', [ self::class, 'flush_pending_deliveries' ], 0 );
	}

	/**
	 * Read-modify-write the coalesced queue under the webhook_queue lock,
	 * retrying the non-blocking acquire a bounded number of times so a
	 * contended lock never causes the caller to drop deliveries. $mutator
	 * receives the freshly-read queue and returns the new queue; persisting
	 * (or deleting when empty) happens inside the lock. Returns true iff the
	 * write actually happened.
	 *
	 * @param callable(array<int, array<int, mixed>>): array<int, array<int, mixed>> $mutator
	 * @return bool
	 */
	private static function run_under_queue_lock( callable $mutator ): bool {
		for ( $attempt = 0; $attempt < self::QUEUE_LOCK_TRIES; $attempt++ ) {
			$ran = Lock::with(
				'webhook_queue',
				10,
				static function () use ( $mutator ): bool {
					// autoload=no option: bust the object cache so we merge onto
					// a fresh DB read, not a copy another process already grew.
					wp_cache_delete( self::QUEUE_OPTION, 'options' );
					$queue = get_option( self::QUEUE_OPTION, [] );
					$queue = is_array( $queue ) ? $queue : [];

					$new = $mutator( $queue );
					$new = is_array( $new ) ? array_values( $new ) : [];

					if ( $new === [] ) {
						delete_option( self::QUEUE_OPTION );
					} else {
						update_option( self::QUEUE_OPTION, $new, false );
					}

					return true;
				}
			);

			if ( true === $ran ) {
				return true;
			}

			usleep( self::QUEUE_LOCK_WAIT_US );
		}

		return false;
	}

	/**
	 * Cap the queue at MAX_QUEUE, dropping the OLDEST overflow. Only a
	 * persistently broken cron can trigger this; the drop is logged on
	 * WP_DEBUG so the condition is diagnosable rather than silent bloat.
	 *
	 * @param array<int, array<int, mixed>> $queue
	 * @return array<int, array<int, mixed>>
	 */
	private static function cap_queue( array $queue ): array {
		if ( count( $queue ) <= self::MAX_QUEUE ) {
			return $queue;
		}

		$overflow = count( $queue ) - self::MAX_QUEUE;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic; the drainer cron is not keeping up and the queue would otherwise grow without bound.
			error_log( sprintf( 'PerfLocale webhook: queue exceeded %d; dropped %d oldest deliveries (drainer cron not keeping up).', self::MAX_QUEUE, $overflow ) );
		}

		return array_slice( $queue, $overflow );
	}

	/**
	 * Flush the in-request delivery buffer into the persistent queue and
	 * schedule ONE drainer tick. Runs at shutdown, so an entire bulk
	 * operation's fan-out becomes a single option write + one cron event.
	 *
	 * @return void
	 */
	public static function flush_pending_deliveries(): void {
		if ( self::$pending_buffer === [] ) {
			return;
		}

		$buffer                 = self::$pending_buffer;
		self::$pending_buffer   = [];
		self::$flush_registered = false;

		// Group by the blog the event fired on. Each blog has its OWN webhook
		// option, queue option, and cron; a delivery buffered while switched
		// into another blog must land in THAT blog's queue, or its drainer —
		// reading this blog's webhooks — would never find the uuid and would
		// silently drop it. The trailing element (index 5) carries the blog id
		// and is stripped before the delivery is written to the per-blog queue.
		$by_blog = [];

		foreach ( $buffer as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$blog_id               = isset( $entry[5] ) ? (int) $entry[5] : 0;
			$by_blog[ $blog_id ][] = array_slice( array_values( $entry ), 0, 5 );
		}

		$current_blog = (int) get_current_blog_id();

		foreach ( $by_blog as $blog_id => $deliveries ) {
			$switched = false;

			if ( $blog_id > 0 && $blog_id !== $current_blog && is_multisite() ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}

			try {
				self::flush_group_into_queue( $deliveries );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Merge one blog's coalesced deliveries into its queue and schedule a
	 * drainer tick. MUST run in the target blog's context — the queue option
	 * and cron are per-blog.
	 *
	 * @param array<int, array<int, mixed>> $deliveries Queue-shaped delivery tuples.
	 * @return void
	 */
	private static function flush_group_into_queue( array $deliveries ): void {
		if ( $deliveries === [] ) {
			return;
		}

		// Locked read-modify-write with bounded retry: a drainer or a second
		// flush in a parallel request would otherwise interleave read/write and
		// drop deliveries. If the (non-blocking) lock cannot be acquired at all,
		// fall back to the pre-coalescing path — one DELIVERY_HOOK event per
		// delivery. This runs ON the shutdown hook, where "restore the buffer
		// and re-arm shutdown" is a dead end (a callback added to the hook
		// currently executing may never fire, or could loop); the per-delivery
		// path is lock-free (no shared queue option), so nothing is lost — it
		// merely forfeits the coalescing win in this extreme edge (~200 ms of
		// continuous queue-lock contention).
		$written = self::run_under_queue_lock(
			static function ( array $queue ) use ( $deliveries ): array {
				return self::cap_queue( array_merge( $queue, $deliveries ) );
			}
		);

		if ( ! $written ) {
			foreach ( $deliveries as $delivery ) {
				if ( is_array( $delivery ) && count( $delivery ) >= 4 ) {
					BackgroundEvents::enqueue( self::DELIVERY_HOOK, array_slice( array_values( $delivery ), 0, 4 ) );
				}
			}

			return;
		}

		if ( ! wp_next_scheduled( self::DRAIN_HOOK ) ) {
			BackgroundEvents::enqueue( self::DRAIN_HOOK, [] );
		}
	}

	/**
	 * Drain up to DRAIN_BATCH queued deliveries, dispatching each through
	 * the normal per-delivery path (unchanged wire contract, unchanged
	 * retry ladder). Reschedules itself while the queue is non-empty.
	 *
	 * @return void
	 */
	public function drain_webhooks(): void {
		// CLAIM a batch atomically: take it out of the shared queue under the
		// lock so a concurrent drainer/flush can't double-dispatch or clobber
		// the write. Dispatch happens OUTSIDE the lock — each deliver_webhook
		// is a blocking POST, and holding the lock across them would stall
		// every other queue op. run_under_queue_lock retries a contended
		// (non-blocking) lock rather than giving up; if it can't acquire at
		// all we simply don't claim (the queue is untouched, a later tick
		// handles it) — never a silent loss.
		$batch = [];
		$more  = false;

		$claimed = self::run_under_queue_lock(
			static function ( array $queue ) use ( &$batch, &$more ): array {
				if ( $queue === [] ) {
					return [];
				}

				$batch = array_splice( $queue, 0, self::DRAIN_BATCH );
				$more  = $queue !== [];

				return $queue;
			}
		);

		if ( ! $claimed || $batch === [] ) {
			return;
		}

		// A worker fatal (timeout/OOM) mid-dispatch would otherwise LOSE the
		// claimed-but-not-yet-sent tail. Re-append whatever is still pending
		// at shutdown so it is redelivered next tick (at-least-once; the SAME
		// X-PerfLocale-Delivery-ID rides on each queue entry, so a receiver
		// dedups the redelivery). Cleared once the loop finishes.
		$pending  = $batch;
		$reappend = static function () use ( &$pending ): void {
			if ( $pending === [] ) {
				return;
			}

			// Undispatched items go to the FRONT to preserve order. Bounded
			// retry so a contended lock at shutdown doesn't drop the tail.
			$requeued = self::run_under_queue_lock(
				static function ( array $queue ) use ( $pending ): array {
					return array_merge( array_values( $pending ), $queue );
				}
			);

			if ( $requeued && ! wp_next_scheduled( self::DRAIN_HOOK ) ) {
				BackgroundEvents::enqueue( self::DRAIN_HOOK, [] );
			}
		};
		register_shutdown_function( $reappend );

		foreach ( $batch as $i => $delivery ) {
			if ( is_array( $delivery ) && count( $delivery ) >= 4 ) {
				$webhook_id  = (string) ( $delivery[0] ?? '' );
				$event       = (string) ( $delivery[1] ?? '' );
				$payload     = (array) ( $delivery[2] ?? [] );
				$timestamp   = (string) ( $delivery[3] ?? '' );
				// 5th element is the stable delivery id minted at enqueue; a
				// legacy 4-tuple (queued before this field existed) falls back
				// to '' and gets an id minted downstream.
				$delivery_id = (string) ( $delivery[4] ?? '' );
				$this->deliver_webhook( $webhook_id, $event, $payload, $timestamp, 1, $delivery_id );
			}

			// Delivered (or dispatched with its own retry scheduled): drop it
			// from the pending set so the shutdown handler won't re-queue it.
			unset( $pending[ $i ] );
		}

		$pending = []; // Whole batch handled — nothing for the shutdown handler.

		if ( $more && ! wp_next_scheduled( self::DRAIN_HOOK ) ) {
			BackgroundEvents::enqueue( self::DRAIN_HOOK, [] );
		}
	}

	/**
	 * Per-event payload key whitelist.
	 *
	 * Only keys listed here are forwarded to webhook subscribers.
	 * Unknown keys are dropped silently (logged on WP_DEBUG).
	 *
	 * @var array<string, array<int, string>>
	 */
	private const EVENT_PAYLOAD_WHITELIST = [
		'translation.created' => [ 'object_id', 'object_type', 'language_slug' ],
		'translation.updated' => [ 'group_id', 'object_id', 'language_id', 'status' ],
		'content.changed'     => [ 'object_id', 'object_type', 'group_id' ],
	];

	/**
	 * Reduce a payload array to its event's whitelisted keys only.
	 *
	 * Scalar/string-only values are accepted through; arrays and objects
	 * are dropped defensively so future callers can't accidentally leak
	 * large or sensitive structures.
	 *
	 * @param string               $event Event name.
	 * @param array<string, mixed> $data Caller-supplied payload.
	 * @return array<string, mixed>
	 */
	private static function filter_payload( string $event, array $data ): array {
		if ( ! isset( self::EVENT_PAYLOAD_WHITELIST[ $event ] ) ) {
			return [];
		}

		$allowed  = self::EVENT_PAYLOAD_WHITELIST[ $event ];
		$filtered = [];
		$dropped  = [];

		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				$dropped[] = $key;
				continue;
			}

			// Accept only scalars - no objects, no nested arrays. Keeps
			// the wire format compact and the surface area predictable.
			if ( ! is_scalar( $value ) && $value !== null ) {
				$dropped[] = $key;
				continue;
			}

			$filtered[ $key ] = $value;
		}

		if ( ! empty( $dropped ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'PerfLocale webhook: dropped non-whitelisted keys for event "%s": %s', $event, implode( ', ', $dropped ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $filtered;
	}

	/**
	 * Cron callback: deliver the webhook, with retry on failure.
	 *
	 * Looks the webhook up fresh from the option (in case it was deleted
	 * between enqueue and fire) and POSTs the signed payload. On non-2xx
	 * or connection error, schedules a retry via RETRY_HOOK up to
	 * MAX_ATTEMPTS total. After the final attempt, the failure is
	 * permanently logged to the FAILURES_KEY option.
	 *
	 * @param string               $webhook_id Webhook UUID.
	 * @param string               $event Event name.
	 * @param array<string, mixed> $data Payload data.
	 * @param string               $timestamp ISO 8601 timestamp captured at enqueue.
	 * @param int                  $attempt Current attempt number (1-based).
	 * @param string               $delivery_id Stable delivery ID across attempts.
	 * @param int                  $breaker_deferrals Times this delivery was already
	 *                                                deferred by an open breaker.
	 * @return void
	 */
	public function deliver_webhook( string $webhook_id, string $event, array $data, string $timestamp, int $attempt = 1, string $delivery_id = '', int $breaker_deferrals = 0 ): void {
		// One logical delivery = one Delivery-ID across ALL attempts. Minted on
		// attempt 1 and threaded through the retry args, so receivers can
		// de-duplicate retries (X-PerfLocale-Attempt already distinguishes
		// them). Defaulted for BC with retry events queued before upgrade.
		if ( '' === $delivery_id ) {
			$delivery_id = wp_generate_uuid4();
		}

		// In-flight safety: a queued delivery firing AFTER uninstall.php
		// removed `perflocale_webhooks` would just hit "webhook deleted"
		// and exit cleanly. But the `record_failure()` path below would
		// race with the option deletion if uninstall is mid-write -
		// bail early to keep the failure log clean.
		if ( \PerfLocale\Plugin::is_uninstalling() ) {
			return;
		}

		// Defense in depth: re-apply the per-event whitelist filter BEFORE
		// HMAC signing, every delivery. The dispatch path already filters
		// at enqueue time, but the cron action arg is just a serialized
		// array — any future bug (or unrelated cron-injection vector) that
		// lets an attacker influence the queued $data would otherwise
		// launder arbitrary attacker-controlled fields through our HMAC
		// signature into trusted downstream systems. Idempotent: a payload
		// that was already whitelisted at enqueue is unchanged here.
		$data = self::filter_payload( $event, $data );

		$webhooks = $this->get_stored_webhooks();

		if ( ! isset( $webhooks[ $webhook_id ] ) ) {
			// Webhook was deleted - discard without logging a failure.
			return;
		}

		$webhook = $webhooks[ $webhook_id ];

		// Re-check URL safety at delivery time in case the filter changed.
		if ( ! $this->is_url_safe( (string) ( $webhook['url'] ?? '' ) ) ) {
			return;
		}

		// Circuit breaker: skip delivery when this specific webhook has
		// tripped its breaker (5+ consecutive failures in 5min). Records
		// the skip in the permanent failure log so the admin can see
		// "circuit_open" entries instead of silent drops. Per-webhook
		// key (not per-URL) so two webhooks pointing at the same host
		// have independent breakers — one operator's broken target
		// doesn't pause another's deliveries.
		$breaker_key = 'webhook_' . $webhook_id;

		if ( \PerfLocale\Concurrency\Breaker::is_open( $breaker_key ) ) {
			$status   = \PerfLocale\Concurrency\Breaker::status( $breaker_key );
			$cooldown = (int) ( $status['cooldown_remaining'] ?? 0 );

			// Defer, don't drop: an open breaker means the receiver is down
			// RIGHT NOW — the event is still deliverable once it recovers.
			// Re-enqueue past the cooldown (same attempt number; this is a
			// deferral, not a failed attempt), bounded by a deferral budget
			// so a permanently-dead receiver still dead-letters instead of
			// circulating forever.
			if ( $breaker_deferrals < self::MAX_BREAKER_DEFERRALS ) {
				try {
					$jitter = random_int( 5, 60 );
				} catch ( \Throwable $e ) {
					$jitter = 30;
				}

				// enqueue() returns a truthful boolean. Discarding it meant a
				// refused admission (an Action Scheduler store error, a
				// pre_schedule_event veto, a duplicate) produced NO retry and NO
				// record: the delivery simply vanished. Fall through to the
				// durable failure log instead, which is bounded and already
				// exists — deliberately NOT a synchronous retry or a busy wait.
				$deferred = BackgroundEvents::enqueue(
					self::RETRY_HOOK,
					[ $webhook_id, $event, $data, $timestamp, $attempt, $delivery_id, $breaker_deferrals + 1 ],
					max( 30, $cooldown ) + $jitter
				);

				if ( ! $deferred ) {
					$this->record_failure(
						$webhook_id,
						$event,
						$timestamp,
						'Retry could not be scheduled while the circuit breaker was open; the delivery was dropped.'
					);
				}

				return;
			}

			$this->record_failure(
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

		// `redirection => 0` is an SSRF guard: is_url_safe() validates the
		// registered URL, but following a redirect would let an attacker host
		// 302 us to 127.0.0.1:6379 / 169.254.169.254 metadata and we'd POST the
		// signed payload there. `reject_unsafe_urls => true` adds defense-in-
		// depth, rejecting private-address resolutions at transport time
		// (partial DNS-rebinding mitigation: public at register, RFC1918 at delivery).
		$response = wp_remote_post(
			$webhook['url'],
			[
				'headers'            => $headers,
				'body'               => $payload,
				'timeout'            => 10,
				// Only the status code is read from the response; without a cap
				// a hostile/misbehaving receiver could stream hundreds of MB
				// into the cron worker within the timeout window.
				'limit_response_size' => KB_IN_BYTES,
				'blocking'           => true,
				'sslverify'          => true,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
			]
		);

		$success = ! is_wp_error( $response )
			&& ( wp_remote_retrieve_response_code( $response ) >= 200 )
			&& ( wp_remote_retrieve_response_code( $response ) < 300 );

		if ( $success ) {
			\PerfLocale\Concurrency\Breaker::record_success( $breaker_key );
			$this->clear_failure( $webhook_id );
			return;
		}

		$response_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		$error = is_wp_error( $response )
			? $response->get_error_message()
			: sprintf( 'HTTP %d', $response_code );

		// Categorise the failure for the breaker. 401/403 from the
		// receiver means the webhook secret rotation broke the auth or
		// the operator misconfigured signing — no amount of retries
		// fixes that, so trip on the first hit. Other failures
		// accumulate toward the default threshold.
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

			// Add jitter ([0, base/2]) so a fan-out of N webhooks failing
			// against the same receiver doesn't pile every retry at exactly
			// `base_delay` seconds later. Deterministic delays produce a
			// thundering herd on the receiver as soon as it recovers from
			// whatever transient error caused the failure — self-inflicted
			// DDoS. Half-base spread is wide enough to flatten the curve
			// without pushing any single retry far from its target slot.
			try {
				$jitter = random_int( 0, max( 1, (int) ( $base_delay / 2 ) ) );
			} catch ( \Throwable $e ) {
				// random_int can throw on hosts with no entropy source;
				// fall back to a fixed fraction so jitter still happens.
				$jitter = (int) ( $base_delay / 4 );
			}

			$delay = max( 1, $base_delay + $jitter );

			$scheduled = BackgroundEvents::enqueue(
				self::RETRY_HOOK,
				[ $webhook_id, $event, $data, $timestamp, $attempt + 1, $delivery_id ],
				$delay
			);

			// Same reasoning as the deferral path above: if the scheduler refuses
			// the retry there will be no further attempt, so this is the last
			// chance to leave a durable record. Without it the `else` below was
			// skipped too and the failure was lost entirely.
			if ( ! $scheduled ) {
				$this->record_failure(
					$webhook_id,
					$event,
					$timestamp,
					$error . ' (retry could not be scheduled; no further attempt will be made)'
				);
			}
		} else {
			$this->record_failure( $webhook_id, $event, $timestamp, $error );
		}
	}

	/**
	 * Append a delivery failure to the permanent failure log.
	 *
	 * Caps the log at 100 entries to prevent option bloat.
	 *
	 * @param string $webhook_id Webhook UUID.
	 * @param string $event Event name.
	 * @param string $timestamp Original event timestamp.
	 * @param string $error Error description.
	 * @return void
	 */
	private function record_failure( string $webhook_id, string $event, string $timestamp, string $error ): void {
		// Defense-in-depth: bound $error to a known-safe shape before it
		// hits the log. Callers only ever pass `$response->get_error_message()`
		// (WP_Error message — cURL/WordPress text) or `sprintf('HTTP %d', $code)`,
		// neither of which contains secrets, response bodies, or the
		// destination URL. But the log is read by every manage_options
		// account on the site (via the Webhooks admin page), so future
		// refactors that widen $error to include receiver response bodies
		// — which can echo back partial HMAC signatures, request IDs that
		// leak internal infrastructure, or credentials a misconfigured
		// receiver pasted into its error path — must NOT silently land
		// here. Allow-list to the two documented shapes; anything else
		// gets the generic placeholder so future-you sees it and updates
		// the contract deliberately.
		if ( preg_match( '/^HTTP \d{1,3}$/', $error ) !== 1 ) {
			// Strip ASCII control chars / NULs that could break the
			// rendered table on the admin page, then bound to 200 chars.
			$error = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $error );

			// The paragraph above assumes a WP_Error message carries no
			// secret. That holds for the transports WordPress ships, but the
			// message is composed by whatever handles the request: a
			// site-local `pre_http_request` filter, a custom transport or a
			// proxy integration can fold the outgoing request - including the
			// webhook signing secret - into it, and this log is rendered to
			// every manage_options account. Mask credential-shaped runs so the
			// assumption is enforced rather than merely documented.
			$error = \PerfLocale\Util\SecretMasker::mask( $error );
		}

		// Serialize the read-modify-write through a lock so two webhook
		// deliveries failing in the same tick don't both read the pre-write
		// log, both append their own entry, and lose one record on the
		// second update_option().
		// Lock::with() returns null when it could not take the lock — it is a
		// NON-BLOCKING mutex, so contention means the critical section never ran.
		// Discarding that meant a real delivery failure could go unrecorded while
		// the admin failure log showed nothing at all. Log the miss instead of
		// retrying or waiting: the section is deliberately short and a busy wait
		// here would be worse than a missing row.
		$logged = Lock::with(
			'webhook_failure_log',
			5,
			function () use ( $webhook_id, $event, $timestamp, $error ): void {
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

				// Cap to last 100 entries.
				if ( count( $log ) > 100 ) {
					$log = array_slice( $log, -100 );
				}

				update_option( self::FAILURES_KEY, $log, false );
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
	 * Remove all failure log entries for a webhook that just succeeded.
	 *
	 * @param string $webhook_id Webhook UUID.
	 * @return void
	 */
	private function clear_failure( string $webhook_id ): void {
		// A lock miss here leaves a stale failure row visible in the admin until
		// the next successful delivery clears it. Self-healing, but the operator
		// is looking at a failure that no longer exists, so make it observable.
		$cleared = Lock::with(
			'webhook_failure_log',
			5,
			function () use ( $webhook_id ): void {
				$log = get_option( self::FAILURES_KEY, [] );

				if ( ! is_array( $log ) || empty( $log ) ) {
					return;
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

	/**
	 * Get all stored webhooks from options.
	 *
	 * @return array<string, array{id: string, url: string, events: array<int, string>, secret: string, created_at: string}>
	 */
	private function get_stored_webhooks(): array {
		$webhooks = get_option( self::OPTION_KEY, [] );

		if ( ! is_array( $webhooks ) ) {
			return [];
		}

		return $webhooks;
	}
}
