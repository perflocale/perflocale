<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$config = [ // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Required by Blocksy panel builder.
	'name'              => __( 'Language Switcher', 'perflocale' ),
	'description'       => __( 'Display a language switcher with flags and names.', 'perflocale' ),
	'clone'             => true,
	// EVERY option in this item's options.php belongs here. Blocksy's builder
	// partial only re-renders when the changed option id appears in this list
	// (static/js/customizer/sync/builder.js:385), and the partial is registered
	// with fallback_refresh = false — so an option left out of it changes
	// nothing in the Customizer preview at all, not even a full reload. The
	// four below were added to options.php after this list was first written.
	'selective_refresh' => [
		'perflocale_switcher_style',
		'perflocale_switcher_display',
		'perflocale_switcher_arrow_style',
		'perflocale_switcher_trigger_format',
		'perflocale_switcher_layout',
		'perflocale_switcher_name_format',
		'perflocale_switcher_show_flags',
		'perflocale_switcher_show_names',
		'perflocale_switcher_hide_current',
		'perflocale_switcher_show_untranslated',
	],
];
