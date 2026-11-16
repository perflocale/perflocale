<?php
/**
 * Schema migration steps.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `migrate_to_N()` steps UpgradeRunner runs before dbDelta when the stored DB version is below N; a step returns ''
 * or why it stopped (then no dbDelta, no stamp).
 *
 * None so far: v2 adds tables only (dbDelta). Each step must be safe to run twice (MySQL DDL cannot roll back):
 * UpgradeIdempotencyTest runs every step twice and compares the schema.
 *
 * @since 1.0.7.5
 */
final class SchemaMigrations {
}
