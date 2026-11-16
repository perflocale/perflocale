<?php
/**
 * The "What it does" texts of Elementor's content types at Settings > Translation; loaded only while that table renders.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'perflocale/settings/content_type_description',
	static fn( mixed $text, string $type ): mixed => match ( $type ) {
		'elementor_library'  => __( 'Templates and sections you saved in Elementor.', 'perflocale' ),
		'e-floating-buttons' => __( 'Elementor floating buttons and bars.', 'perflocale' ),
		'e-landing-page'     => __( 'Landing pages built with Elementor.', 'perflocale' ),
		default              => $text,
	},
	10,
	2
);
