<?php
/**
 * The "What it does" text of Oxygen Classic templates at Settings > Translation; loaded only while that table renders.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'perflocale/settings/content_type_description', static fn( mixed $text, string $type ): mixed => 'ct_template' === $type ? __( 'Oxygen templates and reusable parts.', 'perflocale' ) : $text, 10, 2 );
