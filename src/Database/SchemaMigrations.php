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
 * The `migrate_to_N()` steps UpgradeRunner runs before dbDelta when the stored DB version is below N.
 *
 * None on the v1 schema. Each step must be safe to run twice (MySQL DDL cannot roll back): UpgradeIdempotencyTest
 * runs every step twice and compares the schema.
 *
 * @since 1.0.7.5
 */
final class SchemaMigrations {
}
