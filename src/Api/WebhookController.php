<?php
/**
 * Webhook REST API controller.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Api;

use PerfLocale\Concurrency\Lock;
use PerfLocale\Helper;
use PerfLocale\Webhook\Delivery;
use PerfLocale\Webhook\Dispatcher;
use PerfLocale\Webhook\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../Webhook/Events.php';

/**
 * REST routes for webhooks (register, list, delete); events, queue and delivery live in PerfLocale\Webhook.
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
		Events::register();
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
		// Our files were replaced in this request: load nothing from them.
		if ( defined( 'PERFLOCALE_FILES_REPLACED' ) ) {
			return $this->error( 'perflocale_updating', __( 'PerfLocale was just updated. Try again.', 'perflocale' ), 503 );
		}

		$url    = $request->get_param( 'url' );
		$events = $request->get_param( 'events' );
		$secret = (string) $request->get_param( 'secret' );
		$secret = trim( $secret );

		$safety_reason = '';

		require_once __DIR__ . '/../Webhook/Delivery.php';

		if ( ! Delivery::is_url_safe( $url, true, $safety_reason ) ) {
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
				// Fresh DB read inside the lock: this request's cached copy (alloptions while empty) may predate another
				// holder's write.
				wp_cache_delete( self::OPTION_KEY, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
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
		// Our files were replaced in this request: load nothing from them.
		if ( defined( 'PERFLOCALE_FILES_REPLACED' ) ) {
			return $this->error( 'perflocale_updating', __( 'PerfLocale was just updated. Try again.', 'perflocale' ), 503 );
		}

		// The URL segment only: get_param() prefers a body or query `id`, which could name another webhook.
		$id = sanitize_text_field( $request->get_url_params()['id'] ?? '' );

		// Same lock as register_webhook(): concurrent writes would lose one.
		$result = Lock::with(
			'webhooks_write',
			10,
			function () use ( $id ): bool|string {
				// Fresh DB read inside the lock - see register_webhook().
				wp_cache_delete( self::OPTION_KEY, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				$webhooks = $this->get_stored_webhooks();

				if ( ! isset( $webhooks[ $id ] ) ) {
					return 'not_found';
				}

				unset( $webhooks[ $id ] );
				// Autoloaded only once empty (no secret left). Removing a present key always changes the value, so false is
				// a refused write; no re-read to confirm, as get_option() reads a failed SELECT as a missing option.
				if ( ! update_option( self::OPTION_KEY, $webhooks, [] === $webhooks ) ) {
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
		require_once __DIR__ . '/../Webhook/Delivery.php';
		Delivery::clear_failure( $id );

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
		// Our files were replaced in this request: load nothing from them.
		if ( defined( 'PERFLOCALE_FILES_REPLACED' ) ) {
			return;
		}

		require_once __DIR__ . '/../Webhook/Dispatcher.php';
		Dispatcher::fire( $event, $data );
	}

	/**
	 * At shutdown, flush the delivery buffer into the queue and schedule one drainer tick.
	 *
	 * @return void
	 */
	public static function flush_pending_deliveries(): void {
		// Our files were replaced in this request: load nothing from them.
		if ( defined( 'PERFLOCALE_FILES_REPLACED' ) ) {
			return;
		}

		require_once __DIR__ . '/../Webhook/Dispatcher.php';
		Dispatcher::flush_pending_deliveries();
	}

	/**
	 * Deliver up to one batch of queued deliveries; reschedules itself while the queue is non-empty.
	 *
	 * @return void
	 */
	public function drain_webhooks(): void {
		// Our files were replaced in this request: load nothing from them.
		if ( defined( 'PERFLOCALE_FILES_REPLACED' ) ) {
			return;
		}

		require_once __DIR__ . '/../Webhook/Delivery.php';
		Delivery::drain();
	}

	/**
	 * POST the signed payload; retry a failure up to the attempt limit, then log it.
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
		// Our files were replaced in this request: load nothing from them.
		if ( defined( 'PERFLOCALE_FILES_REPLACED' ) ) {
			return;
		}

		require_once __DIR__ . '/../Webhook/Delivery.php';
		Delivery::deliver( $webhook_id, $event, $data, $timestamp, $attempt, $delivery_id, $breaker_deferrals );
	}

	/**
	 * Get all stored webhooks from options.
	 *
	 * @return array<string, array{id: string, url: string, events: array<int, string>, secret: string, created_at: string}>
	 */
	private function get_stored_webhooks(): array {
		return Events::stored();
	}
}
