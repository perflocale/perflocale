<?php
/**
 * Webhook event listeners and the stored webhook list.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires translation events to Dispatcher; loaded by a literal require on write-context requests with webhooks or on
 * multisite, so it stays small (outside the classmap).
 *
 * @internal
 */
final class Events {

	/**
	 * Option holding the registered webhooks.
	 */
	public const OPTION_KEY = 'perflocale_webhooks';

	/**
	 * Blogs whose autoloaded list this request already tried to move out (one attempt each, so a lost race costs no more).
	 *
	 * @var array<int, true>
	 */
	private static array $move_out_tried = [];

	/**
	 * Add the event listeners when webhooks may fire on this request.
	 *
	 * @return void
	 */
	public static function register(): void {
		// No webhooks, no listeners. Single site only: on multisite this blog's option says nothing about blogs
		// the request may switch into, so Dispatcher::fire() decides per blog.
		if ( ! is_multisite() ) {
			$webhooks = get_option( self::OPTION_KEY, [] );

			if ( empty( $webhooks ) || ! is_array( $webhooks ) ) {
				return;
			}

			// Secrets stay out of alloptions; a writer that keeps the autoload (WP-CLI, a row restore) leaves them in.
			if ( isset( wp_load_alloptions()[ self::OPTION_KEY ] ) ) {
				\PerfLocale\Activator::seed_webhooks_row();
			}
		} else {
			self::move_autoloaded_list_out();
		}

		add_action( 'perflocale/translation/created', [ self::class, 'translation_created' ], 10, 3 );
		add_action( 'perflocale/translation/linked', [ self::class, 'translation_linked' ], 10, 3 );
		add_action( 'perflocale/translation/status_changed', [ self::class, 'status_changed' ], 10, 3 );
		add_action( 'perflocale/content/changed', [ self::class, 'content_changed' ], 10, 3 );
	}

	/**
	 * `perflocale/translation/created` listener.
	 *
	 * @param int    $object_id   Object ID.
	 * @param string $type        Object type.
	 * @param string $target_slug Language slug.
	 * @return void
	 */
	public static function translation_created( int $object_id, string $type, string $target_slug ): void {
		// Our files were replaced in this request: load nothing from them.
		if ( defined( 'PERFLOCALE_FILES_REPLACED' ) ) {
			return;
		}

		require_once __DIR__ . '/Dispatcher.php';
		Dispatcher::fire(
			'translation.created',
			[
				'object_id'     => $object_id,
				'object_type'   => $type,
				'language_slug' => $target_slug,
			]
		);
	}

	/**
	 * `perflocale/translation/linked` listener.
	 *
	 * @param int $group_id    Group ID.
	 * @param int $object_id   Object ID.
	 * @param int $language_id Language ID.
	 * @return void
	 */
	public static function translation_linked( int $group_id, int $object_id, int $language_id ): void {
		// Our files were replaced in this request: load nothing from them.
		if ( defined( 'PERFLOCALE_FILES_REPLACED' ) ) {
			return;
		}

		require_once __DIR__ . '/Dispatcher.php';
		Dispatcher::fire(
			'translation.updated',
			[
				'group_id'    => $group_id,
				'object_id'   => $object_id,
				'language_id' => $language_id,
			]
		);
	}

	/**
	 * `perflocale/translation/status_changed` listener.
	 *
	 * @param int    $object_id   Object ID.
	 * @param string $status      New status.
	 * @param int    $language_id Language ID.
	 * @return void
	 */
	public static function status_changed( int $object_id, string $status, int $language_id ): void {
		// Our files were replaced in this request: load nothing from them.
		if ( defined( 'PERFLOCALE_FILES_REPLACED' ) ) {
			return;
		}

		require_once __DIR__ . '/Dispatcher.php';
		Dispatcher::fire(
			'translation.updated',
			[
				'object_id'   => $object_id,
				'status'      => $status,
				'language_id' => $language_id,
			]
		);
	}

	/**
	 * `perflocale/content/changed` listener.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $type      Object type.
	 * @param int    $group_id  Group ID.
	 * @return void
	 */
	public static function content_changed( int $object_id, string $type, int $group_id ): void {
		// Our files were replaced in this request: load nothing from them.
		if ( defined( 'PERFLOCALE_FILES_REPLACED' ) ) {
			return;
		}

		require_once __DIR__ . '/Dispatcher.php';
		Dispatcher::fire(
			'content.changed',
			[
				'object_id'   => $object_id,
				'object_type' => $type,
				'group_id'    => $group_id,
			]
		);
	}

	/**
	 * The stored webhooks of the current blog.
	 *
	 * @return array<string, array{id: string, url: string, events: array<int, string>, secret: string, created_at: string}>
	 */
	public static function stored(): array {
		$webhooks = get_option( self::OPTION_KEY, [] );

		// A switched blog's list is moved out of alloptions when its event fires (get_option() loaded them).
		if ( is_multisite() ) {
			self::move_autoloaded_list_out();
		}

		if ( ! is_array( $webhooks ) ) {
			return [];
		}

		return $webhooks;
	}

	/**
	 * Multisite: move the current blog's list out of autoload when its alloptions (already in memory) hold one.
	 * Per blog: seed_webhooks_row() writes $wpdb->options and drops cache keys, both repointed by switch_to_blog().
	 *
	 * @return void
	 */
	private static function move_autoloaded_list_out(): void {
		$blog = get_current_blog_id();
		$raw  = wp_load_alloptions()[ self::OPTION_KEY ] ?? null;

		if ( ! is_string( $raw ) || isset( self::$move_out_tried[ $blog ] ) ) {
			return;
		}

		$list = maybe_unserialize( $raw );

		if ( is_array( $list ) && [] !== $list ) {
			self::$move_out_tried[ $blog ] = true;
			\PerfLocale\Activator::seed_webhooks_row();
		}
	}
}
