<?php
/**
 * Who owns the transaction of a URL-slug restore.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CALLER: inside the caller's open transaction (Replace import); OWN: one transaction per namespace (Merge).
 *
 * @since 1.0.8
 */
enum Txn {
	case CALLER;
	case OWN;
}
