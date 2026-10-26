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
 * Raised when the import lock is held or a running import lost it; the message is translated and safe to show.
 *
 * A background job leaves it uncaught on purpose, so the worker retries the job later.
 */
final class MigrationLockedException extends \RuntimeException {
}
