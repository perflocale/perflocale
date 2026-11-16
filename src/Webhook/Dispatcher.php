<?php
/**
 * Webhook event fan-out and the coalesced delivery queue.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Webhook;

use PerfLocale\Background\BackgroundEvents;
use PerfLocale\Concurrency\Lock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/Events.php';

/**
 * Queues one delivery per subscribed webhook when an event fires; loaded by a literal require (outside the classmap).
 *
 * @internal
 */
final class Dispatcher {

	/**
	 * Cron hooks (copies of WebhookController's, kept equal by HookNameDriftTest).
	 */
	public const DELIVERY_HOOK = 'perflocale_deliver_webhook';
	public const RETRY_HOOK    = 'perflocale_retry_webhook';
	public const DRAIN_HOOK    = 'perflocale_drain_webhooks';

	/**
	 * Non-autoloaded option holding queued deliveries awaiting the drainer.
	 */
	public const QUEUE_OPTION = 'perflocale_webhook_queue';

	/**
	 * Deliveries per drainer tick: each is a blocking POST, so one tick stays short; it reschedules while work remains.
	 */
	public const DRAIN_BATCH = 20;

	/**
	 * Bounded retry for the non-blocking webhook_queue lock: its section is one fast option write, so retrying wins.
	 */
	private const QUEUE_LOCK_TRIES   = 40;
	private const QUEUE_LOCK_WAIT_US = 5000;

	/**
	 * Hard ceiling on the coalesced queue (reached only with broken cron); the oldest overflow is dropped.
	 */
	private const MAX_QUEUE = 10000;

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
	 * The translation.created events this request queued, by blog, type and object (a recovery's retry fires it again).
	 *
	 * @var array<string, true>
	 */
	private static array $created_sent = [];

	/**
	 * Fire webhooks for an event: queued per subscriber, delivered in the background.
	 *
	 * @param string               $event Event name (e.g., 'translation.created').
	 * @param array<string, mixed> $data Event payload data.
	 * @return void
	 */
	public static function fire( string $event, array $data ): void {
		// A staging clone carries production webhook URLs: fail closed (perflocale/dispatch/allow_non_production opts in).
		if ( ! \PerfLocale\Helper::is_outbound_dispatch_allowed( 'webhooks' ) ) {
			return;
		}

		$webhooks = Events::stored();

		if ( empty( $webhooks ) ) {
			return;
		}

		// Per-event key whitelist, so extra hook data never leaks to a receiver.
		$data = self::filter_payload( $event, $data );

		if ( 'translation.created' === $event ) {
			$once = (string) wp_json_encode( [ get_current_blog_id(), $data['object_type'] ?? '', $data['object_id'] ?? '' ] );

			if ( isset( self::$created_sent[ $once ] ) ) {
				return;
			}

			self::$created_sent[ $once ] = true;
		}

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
	public static function run_under_queue_lock( callable $mutator ): ?bool {
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
	public static function forget_listed_absence( string $option ): void {
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
	public static function schedule_unqueued( array $deliveries ): void {
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
	 * Reduce a payload to its event's whitelisted keys with scalar values.
	 *
	 * @param string               $event Event name.
	 * @param array<string, mixed> $data Caller-supplied payload.
	 * @return array<string, mixed>
	 */
	public static function filter_payload( string $event, array $data ): array {
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
}
