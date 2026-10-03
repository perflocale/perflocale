<?php
/**
 * Thrown when an import is refused because another import holds the lock.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raised by {@see MigrationLock::run()} when the `migration_import` lock is
 * held, and by its heartbeat when a running import lost the lock. The
 * message is translated and safe to show to the operator.
 *
 * In a background job the exception is left uncaught, so the worker retries
 * the job later; the admin's inline path and WP-CLI show the message.
 */
final class MigrationLockedException extends \RuntimeException {
}
