<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$config = [ // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Required by Blocksy panel builder.
	'name'              => __( 'Language Switcher', 'perflocale' ),
	'description'       => __( 'Display a language switcher with flags and names.', 'perflocale' ),
	'clone'             => true,
	// Every option of options.php belongs here: Blocksy's builder partial re-renders only for listed ids
	// and has fallback_refresh = false, so an unlisted option changes nothing in the Customizer preview.
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
