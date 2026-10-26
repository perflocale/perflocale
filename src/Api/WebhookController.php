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
use PerfLocale\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for webhooks (register, list, delete) and the signed delivery of translation events.
 *
 * Delivery design (SSRF checks, queue, failure log): docs-ai/11-code-rationale.md, "WebhookController: URL safety checks".
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
	 * Cron hook for deliveries that carry their delivery id: retries, breaker deferrals, and attempt 1 after a refused queue write.
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
	 * Deliveries per drainer tick: each is a blocking POST, so one tick stays short; it reschedules while work remains.
	 */
	private const DRAIN_BATCH = 20;

	/**
	 * Bounded retry for the non-blocking webhook_queue lock: its section is one fast option write, so retrying wins.
	 */
	private const QUEUE_LOCK_TRIES   = 40;
	private const QUEUE_LOCK_WAIT_US  = 5000;

	/**
	 * Hard ceiling on the coalesced queue (reached only with broken cron); the oldest overflow is dropped.
	 */
	private const MAX_QUEUE = 10000;

	/**
	 * In-request delivery buffer, flushed once at shutdown into QUEUE_OPTION.
	 *
	 * One option write and one drainer event, not one cron event (a rewrite of the `cron` option) per delivery.
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
	 * Minimum HMAC secret length (characters).
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
	 * The event names a webhook may subscribe to.
	 *
	 * @return array<int, string>
	 */
	public static function valid_events(): array {
		return self::VALID_EVENTS;
	}

	/**
	 * Wire the webhook dispatcher to the plugin's translation and content hooks.
	 *
	 * @return void
	 */
	public static function register_event_dispatchers(): void {
		// No webhooks, no listeners. Single site only: on multisite this blog's option says nothing about blogs
		// the request may switch into, so fire_webhook() decides per blog.
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
					'permission_callback' => [ $this, 'register_permissions_check' ],
					'args'                => [
						'url'    => [
							'required'          => true,
							'type'              => 'string',
							'validate_callback' => static function ( $value ): bool {
								// is_string first: a non-string would throw a TypeError (500) from the strict is_valid_url().
								return is_string( $value ) && Helper::is_valid_url( $value );
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
	 * Who may create a webhook (an outbound request to an address the caller chose).
	 *
	 * Multisite needs `manage_network_options`: a subsite admin has `manage_options` but not `install_plugins`.
	 * Listing and deleting stay at `manage_options`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function register_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! self::user_can_register( $request ) ) {
			return new \WP_Error(
				'rest_forbidden',
				self::register_denied_message(),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Whether the current user may register webhooks; the import and the listing ask it too.
	 *
	 * @param \WP_REST_Request|null $request The registration request, or null.
	 * @return bool
	 */
	public static function user_can_register( ?\WP_REST_Request $request = null ): bool {
		$fallback = is_multisite() ? 'manage_network_options' : 'manage_options';

		/**
		 * Capability required to register an outbound webhook.
		 *
		 * @hook perflocale/webhooks/register_capability
		 *
		 * @param string           $capability Default `manage_network_options` on multisite, `manage_options` otherwise.
		 * @param \WP_REST_Request $request    The REST request being authorised.
		 */
		$capability = (string) apply_filters(
			'perflocale/webhooks/register_capability',
			$fallback,
			$request ?? new \WP_REST_Request( 'POST', '/perflocale/v1/webhooks' )
		);

		// A filter that returns nothing usable must not open the route.
		if ( '' === $capability ) {
			$capability = $fallback;
		}

		return current_user_can( $capability );
	}

	/**
	 * The message for a user {@see self::user_can_register()} refuses.
	 *
	 * @return string
	 */
	public static function register_denied_message(): string {
		return is_multisite()
			? __( 'Registering a webhook requires network administrator permissions.', 'perflocale' )
			: __( 'You do not have permission to perform this action.', 'perflocale' );
	}

	/**
	 * A webhook address for a user who may not register webhooks: path and query become `[REDACTED]`.
	 *
	 * @param string $url Stored address.
	 * @return string
	 */
	public static function redacted_url( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return '[REDACTED]';
		}

		$out  = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$rest = ( $parts['path'] ?? '' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

		return $out . ( '' === $rest || '/' === $rest ? $rest : '/[REDACTED]' );
	}

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
	private function is_url_safe( string $url, bool $filterable = true, ?string &$reason = null, ?array &$addresses = null ): bool {
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

				if ( ! $this->is_url_safe( 'https://[' . $ipv6 . ']/', false, $ipv6_reason ) ) {
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
	 * Verify the `X-PerfLocale-Signature` header (HMAC of the raw body only) in constant time.
	 *
	 * A receiver should also refuse a stale `timestamp` (with a 4xx other than 401/403, which pause the webhook) and
	 * de-duplicate by `X-PerfLocale-Delivery-ID`: https://perflocale.com/docs/rest-api/#verifying-a-delivery.
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

		$safety_reason = '';

		if ( ! $this->is_url_safe( $url, true, $safety_reason ) ) {
			// Name the rule that refused the URL.
			$messages = [
				'unparseable_url'  => __( 'Webhook URL could not be parsed.', 'perflocale' ),
				'unsupported_scheme' => __( 'Webhook URL must use http or https.', 'perflocale' ),
				'userinfo_in_url'  => __( 'Webhook URL must not contain a username or password.', 'perflocale' ),
				'disallowed_port'  => __( 'Webhook URL uses a port WordPress will not connect to. Allowed ports are 80, 443 and 8080.', 'perflocale' ),
				'unresolvable'     => __( 'Webhook URL host could not be resolved.', 'perflocale' ),
				'private_address'  => __( 'Webhook URL targets a private-range address.', 'perflocale' ),
				'loopback_address' => __( 'Webhook URL targets a loopback address.', 'perflocale' ),
				'rejected'         => __( 'Webhook URL was rejected by a site policy filter.', 'perflocale' ),
			];

			return $this->error(
				'unsafe_url',
				$messages[ $safety_reason ] ?? __( 'Webhook URL targets a loopback or private-range address.', 'perflocale' )
			);
		}

		// No secret supplied: generate one, so every webhook is signed.
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

		// The read-modify-write runs under a lock, or concurrent registrations would lose one.
		$id       = wp_generate_uuid4();
		$response = Lock::with(
			'webhooks_write',
			10,
			function () use ( $id, $url, $events, $secret ): array|string {
				// Fresh DB read inside the lock: this request's cached copy may predate another holder's write.
				wp_cache_delete( self::OPTION_KEY, 'options' );
				$webhooks = $this->get_stored_webhooks();

				$webhooks[ $id ] = [
					'id'         => $id,
					'url'        => $url,
					'events'     => $events,
					'secret'     => $secret,
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				];

				// Not autoloaded: HMAC secrets stay out of `alloptions`. A new uuid always changes the value, so false is a
				// refused write, not a no-op.
				if ( ! update_option( self::OPTION_KEY, $webhooks, false ) ) {
					return 'write_failed';
				}

				return $webhooks[ $id ];
			}
		);

		if ( $response === null ) {
			return $this->error( 'lock_unavailable', __( 'Another webhook registration is in progress; please retry.', 'perflocale' ), 503 );
		}

		// Never hand out a secret for a webhook that was not stored.
		if ( $response === 'write_failed' ) {
			return $this->error( 'create_failed', __( 'The webhook could not be saved; nothing was registered.', 'perflocale' ), 500 );
		}

		// The secret is returned once, here; GET /webhooks never includes it.
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
		$full_url = self::user_can_register();

		// No secrets; a user who may not register webhooks sees each address without its path and query.
		$safe = array_map(
			static function ( array $hook ) use ( $full_url ): array {
				unset( $hook['secret'] );

				if ( ! $full_url ) {
					$hook['url'] = self::redacted_url( $hook['url'] );
				}

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
		// The URL segment only: get_param() prefers a body or query `id`, which could name another webhook.
		$id = sanitize_text_field( $request->get_url_params()['id'] ?? '' );

		// Same lock as register_webhook(): concurrent writes would lose one.
		$result = Lock::with(
			'webhooks_write',
			10,
			function () use ( $id ): bool|string {
				// Fresh DB read inside the lock - see register_webhook().
				wp_cache_delete( self::OPTION_KEY, 'options' );
				$webhooks = $this->get_stored_webhooks();

				if ( ! isset( $webhooks[ $id ] ) ) {
					return 'not_found';
				}

				unset( $webhooks[ $id ] );
				// Not autoloaded (secrets). Removing a present key always changes the value, so false is a refused write;
				// no re-read to confirm, as get_option() reads a failed SELECT as a missing option.
				if ( ! update_option( self::OPTION_KEY, $webhooks, false ) ) {
					return 'write_failed';
				}

				return true;
			}
		);

		if ( $result === null ) {
			return $this->error( 'lock_unavailable', __( 'Another webhook write is in progress; please retry.', 'perflocale' ), 503 );
		}

		if ( $result === 'not_found' ) {
			return $this->error( 'not_found', __( 'Webhook not found.', 'perflocale' ), 404 );
		}

		// Before clear_failure(): a webhook that is still registered keeps its failure history.
		if ( $result === 'write_failed' ) {
			return $this->error( 'delete_failed', __( 'The webhook could not be deleted; it is still registered.', 'perflocale' ), 500 );
		}

		// Drop the deleted webhook's failure log entries.
		$this->clear_failure( $id );

		return $this->success( [ 'deleted' => true ] );
	}

	/**
	 * Fire webhooks for an event: queued per subscriber, delivered in the background.
	 *
	 * @param string               $event Event name (e.g., 'translation.created').
	 * @param array<string, mixed> $data Event payload data.
	 * @return void
	 */
	public function fire_webhook( string $event, array $data ): void {
		// A staging clone carries production webhook URLs: fail closed (perflocale/dispatch/allow_non_production opts in).
		if ( ! \PerfLocale\Helper::is_outbound_dispatch_allowed( 'webhooks' ) ) {
			return;
		}

		$webhooks = $this->get_stored_webhooks();

		if ( empty( $webhooks ) ) {
			return;
		}

		// Per-event key whitelist, so extra hook data never leaks to a receiver.
		$data = self::filter_payload( $event, $data );

		// On WP-Cron every scheduled event rewrites the autoloaded `cron` option, so deliveries are coalesced into one
		// queue; Action Scheduler stores each action as its own row and enqueues directly.
		$coalesce = ! BackgroundEvents::is_action_scheduler_engine();
		$ts       = gmdate( 'Y-m-d\TH:i:s\Z' );

		foreach ( $webhooks as $webhook ) {
			if ( ! in_array( $event, $webhook['events'], true ) ) {
				continue;
			}

			if ( $coalesce ) {
				// The delivery id is minted once here, so a redelivery keeps it for receiver dedup. The blog id routes the
				// shutdown flush to that blog's queue (webhooks, queue and cron are per blog).
				self::$pending_buffer[] = [ $webhook['id'], $event, $data, $ts, wp_generate_uuid4(), get_current_blog_id() ];
				continue;
			}

			// Action Scheduler: the id is minted on the first delivery and reused by its retries.
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
	 * Read-modify-write the coalesced queue under the webhook_queue lock, with a bounded acquire retry.
	 *
	 * True: stored; false: lock taken but the write refused (never a no-op, as every caller changes the queue);
	 * null: no lock. The write's own result is used, as get_option() reads a failed SELECT as a missing option.
	 *
	 * @param callable(array<int, array<int, mixed>>): array<int, array<int, mixed>> $mutator
	 * @return bool|null
	 */
	private static function run_under_queue_lock( callable $mutator ): ?bool {
		for ( $attempt = 0; $attempt < self::QUEUE_LOCK_TRIES; $attempt++ ) {
			$ran = Lock::with(
				'webhook_queue',
				10,
				static function () use ( $mutator ): bool {
					// Fresh DB read, not a cached copy another process has since grown.
					wp_cache_delete( self::QUEUE_OPTION, 'options' );
					self::forget_listed_absence( self::QUEUE_OPTION );
					$queue = get_option( self::QUEUE_OPTION, [] );
					$queue = is_array( $queue ) ? $queue : [];

					$new = $mutator( $queue );
					$new = is_array( $new ) ? array_values( $new ) : [];

					if ( $new === [] ) {
						return delete_option( self::QUEUE_OPTION );
					}

					return update_option( self::QUEUE_OPTION, $new, false );
				}
			);

			if ( null !== $ran ) {
				return true === $ran;
			}

			usleep( self::QUEUE_LOCK_WAIT_US );
		}

		return null;
	}

	/**
	 * Unlist $option from `notoptions` before a locked read: another request may have created the row since.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	private static function forget_listed_absence( string $option ): void {
		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			\PerfLocale\Database\NotOptions::unlist( [ $option ] );
		}
	}

	/**
	 * Schedule deliveries whose queue write was refused one by one (attempt 1 on RETRY_HOOK), keeping their ids.
	 *
	 * Capped at DRAIN_BATCH, as a refusal can repeat on every flush; the lost rest is always logged.
	 *
	 * @param array<int, array<int, mixed>> $deliveries Queue-shaped delivery tuples.
	 * @return void
	 */
	private static function schedule_unqueued( array $deliveries ): void {
		$scheduled = 0;

		foreach ( array_slice( $deliveries, 0, self::DRAIN_BATCH ) as $delivery ) {
			if ( count( $delivery ) < 4 ) {
				continue;
			}

			$args   = array_slice( array_values( $delivery ), 0, 4 );
			$args[] = 1;
			$args[] = $delivery[4] ?? '';

			if ( ! BackgroundEvents::enqueue( self::RETRY_HOOK, $args ) ) {
				break;
			}

			++$scheduled;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A refused queue write loses the deliveries past the cap; that must never be silent.
		error_log( sprintf( 'PerfLocale webhook: the delivery queue could not be saved; %1$d deliveries were scheduled individually and %2$d were dropped.', $scheduled, count( $deliveries ) - $scheduled ) );
	}

	/**
	 * Cap the queue at MAX_QUEUE, dropping the oldest overflow (logged on WP_DEBUG).
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
	 * At shutdown, flush the delivery buffer into the queue and schedule one drainer tick.
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

		// By the blog the event fired on (index 5, stripped): another blog's drainer would never find the webhook.
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
	 * Merge one blog's deliveries into its queue and schedule a drainer tick; runs in that blog's context.
	 *
	 * @param array<int, array<int, mixed>> $deliveries Queue-shaped delivery tuples.
	 * @return void
	 */
	private static function flush_group_into_queue( array $deliveries ): void {
		if ( $deliveries === [] ) {
			return;
		}

		// No lock at all: one DELIVERY_HOOK event per delivery. Re-arming shutdown from inside shutdown may never fire.
		$written = self::run_under_queue_lock(
			static function ( array $queue ) use ( $deliveries ): array {
				return self::cap_queue( array_merge( $queue, $deliveries ) );
			}
		);

		if ( null === $written ) {
			foreach ( $deliveries as $delivery ) {
				if ( is_array( $delivery ) && count( $delivery ) >= 4 ) {
					BackgroundEvents::enqueue( self::DELIVERY_HOOK, array_slice( array_values( $delivery ), 0, 4 ) );
				}
			}

			return;
		}

		// Refused write: the capped one-by-one fallback, and no drain for a flush that stored nothing.
		if ( false === $written ) {
			self::schedule_unqueued( $deliveries );

			return;
		}

		if ( ! wp_next_scheduled( self::DRAIN_HOOK ) ) {
			BackgroundEvents::enqueue( self::DRAIN_HOOK, [] );
		}
	}

	/**
	 * Deliver up to DRAIN_BATCH queued deliveries; reschedules itself while the queue is non-empty.
	 *
	 * @return void
	 */
	public function drain_webhooks(): void {
		// Claim the batch under the lock, deliver outside it (blocking POSTs). No lock: nothing claimed, a later tick runs.
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
			$requeued = self::run_under_queue_lock(
				static function ( array $queue ) use ( $pending ): array {
					return array_merge( array_values( $pending ), $queue );
				}
			);

			if ( true === $requeued && ! wp_next_scheduled( self::DRAIN_HOOK ) ) {
				BackgroundEvents::enqueue( self::DRAIN_HOOK, [] );
			} elseif ( false === $requeued ) {
				self::schedule_unqueued( $pending );
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
				$this->deliver_webhook( $webhook_id, $event, $payload, $timestamp, 1, $delivery_id );
			}

			// Handled (delivered or retry scheduled): the shutdown handler skips it.
			unset( $pending[ $i ] );
		}

		$pending = []; // Whole batch handled - nothing for the shutdown handler.

		if ( $more && ! wp_next_scheduled( self::DRAIN_HOOK ) ) {
			BackgroundEvents::enqueue( self::DRAIN_HOOK, [] );
		}
	}

	/**
	 * Per-event payload key whitelist; other keys are dropped (logged on WP_DEBUG).
	 *
	 * @var array<string, array<int, string>>
	 */
	private const EVENT_PAYLOAD_WHITELIST = [
		'translation.created' => [ 'object_id', 'object_type', 'language_slug' ],
		'translation.updated' => [ 'group_id', 'object_id', 'language_id', 'status' ],
		'content.changed'     => [ 'object_id', 'object_type', 'group_id' ],
	];

	/**
	 * Reduce a payload to its event's whitelisted keys with scalar values.
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

			// Scalars only: no objects or nested arrays.
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
	public function deliver_webhook( string $webhook_id, string $event, array $data, string $timestamp, int $attempt = 1, string $delivery_id = '', int $breaker_deferrals = 0 ): void {
		// One Delivery-ID across all attempts, for receiver dedup; an event without one gets it here.
		if ( '' === $delivery_id ) {
			$delivery_id = wp_generate_uuid4();
		}

		// During uninstall record_failure() would race the option deletion.
		if ( \PerfLocale\Plugin::is_uninstalling() ) {
			return;
		}

		// Whitelist again before signing: the cron argument is a stored array, and only whitelisted fields get signed.
		$data = self::filter_payload( $event, $data );

		$webhooks = $this->get_stored_webhooks();

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

		if ( ! $this->is_url_safe( $url, true, $safety_reason, $addresses ) ) {
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
					self::RETRY_HOOK,
					[ $webhook_id, $event, $data, $timestamp, $attempt + 1, $delivery_id ],
					max( 1, $base_delay + $jitter )
				);

				if ( $requeued ) {
					return;
				}

				// The scheduler refused the retry: this is the last chance to leave a record.
				$this->record_failure(
					$webhook_id,
					$event,
					$timestamp,
					'The webhook host could not be resolved, and a retry could not be scheduled; the delivery was dropped.'
				);

				return;
			}

			$this->record_failure(
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
			$this->record_failure( $webhook_id, $event, $timestamp, sprintf( 'The webhook URL was refused at delivery time (%s).', 'proxied' ) );

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
			$this->clear_failure( $webhook_id );
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
				self::RETRY_HOOK,
				[ $webhook_id, $event, $data, $timestamp, $attempt + 1, $delivery_id ],
				$delay
			);

			// A refused retry means no further attempt: record the failure now.
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
	private function record_failure( string $webhook_id, string $event, string $timestamp, string $error ): void {
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
		self::forget_listed_absence( self::FAILURES_KEY );
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
	private function clear_failure( string $webhook_id ): void {
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
