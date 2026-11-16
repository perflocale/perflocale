<?php
/**
 * The "What it does" text of WooCommerce products at Settings > Translation; loaded only while that table renders.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'perflocale/settings/content_type_description', static fn( mixed $text, string $type ): mixed => 'product' === $type ? __( "Your shop's products: names, descriptions and variations.", 'perflocale' ) : $text, 10, 2 );
