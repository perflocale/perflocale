<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$options = [ // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Required by Blocksy panel builder.
	'perflocale_switcher_style'             => [
		'label'   => __( 'Style', 'perflocale' ),
		'type'    => 'ct-select',
		'value'   => 'flags_names',
		'design'  => 'inline',
		'choices' => blocksy_ordered_keys(
			[
			'flags_names' => __( 'Flags + Names', 'perflocale' ),
			'names_only'  => __( 'Names Only', 'perflocale' ),
			'flags_only'  => __( 'Flags Only', 'perflocale' ),
			]
		),
	],

	'perflocale_switcher_display'           => [
		'label'   => __( 'Display Mode', 'perflocale' ),
		'type'    => 'ct-select',
		'value'   => 'inline',
		'design'  => 'inline',
		'choices' => blocksy_ordered_keys(
			[
			'inline'   => __( 'Inline', 'perflocale' ),
			'simple'   => __( 'Simple', 'perflocale' ),
			'dropdown' => __( 'Dropdown', 'perflocale' ),
			]
		),
	],

	// Dropdown arrow - hidden unless Display Mode is Dropdown (see header
	// language-switcher options.php for rationale).
	'perflocale_switcher_arrow_style'       => [
		'label'     => __( 'Dropdown Arrow', 'perflocale' ),
		'desc'      => __( 'Icon shown next to the language label on the dropdown trigger.', 'perflocale' ),
		'type'      => 'ct-select',
		'value'     => 'single',
		'design'    => 'inline',
		'choices'   => blocksy_ordered_keys(
			[
			'single' => __( 'Single arrow (down chevron)', 'perflocale' ),
			'double' => __( 'Double arrow (up + down chevrons)', 'perflocale' ),
			'none'   => __( 'No arrow', 'perflocale' ),
			]
		),
		'condition' => [ 'perflocale_switcher_display' => 'dropdown' ],
	],

	// Trigger label format - dropdown-only, same condition gating.
	'perflocale_switcher_trigger_format'    => [
		'label'     => __( 'Trigger Label Format', 'perflocale' ),
		'desc'      => __( 'Label format on the dropdown trigger button, independent of the options list.', 'perflocale' ),
		'type'      => 'ct-select',
		'value'     => '',
		'design'    => 'inline',
		'choices'   => blocksy_ordered_keys(
			[
			''        => __( 'Use site default', 'perflocale' ),
			'inherit' => __( 'Match Name Format', 'perflocale' ),
			'native'  => __( 'Native (Deutsch)', 'perflocale' ),
			'english' => __( 'English (German)', 'perflocale' ),
			'both'    => __( 'Both (Deutsch / German)', 'perflocale' ),
			'slug'    => __( 'Code only (DE)', 'perflocale' ),
			]
		),
		'condition' => [ 'perflocale_switcher_display' => 'dropdown' ],
	],

	'perflocale_switcher_layout'            => [
		'label'   => __( 'Layout', 'perflocale' ),
		'type'    => 'ct-select',
		'value'   => 'horizontal',
		'design'  => 'inline',
		'choices' => blocksy_ordered_keys(
			[
			'horizontal' => __( 'Horizontal', 'perflocale' ),
			'vertical'   => __( 'Vertical', 'perflocale' ),
			]
		),
	],

	'perflocale_switcher_name_format'       => [
		'label'   => __( 'Name Format', 'perflocale' ),
		'type'    => 'ct-select',
		'value'   => 'native',
		'design'  => 'inline',
		'choices' => blocksy_ordered_keys(
			[
			'native'  => __( 'Native name', 'perflocale' ),
			'english' => __( 'English name', 'perflocale' ),
			'both'    => __( 'Both', 'perflocale' ),
			'slug'    => __( 'Code (EN)', 'perflocale' ),
			]
		),
	],

	'perflocale_switcher_show_flags'        => [
		'label' => __( 'Show Flags', 'perflocale' ),
		'type'  => 'ct-switch',
		'value' => 'yes',
	],

	'perflocale_switcher_show_names'        => [
		'label' => __( 'Show Names', 'perflocale' ),
		'type'  => 'ct-switch',
		'value' => 'yes',
	],

	'perflocale_switcher_hide_current'      => [
		'label' => __( 'Hide Current Language', 'perflocale' ),
		'type'  => 'ct-switch',
		'value' => 'no',
	],

	'perflocale_switcher_show_untranslated' => [
		'label' => __( 'Show Untranslated', 'perflocale' ),
		'type'  => 'ct-switch',
		'value' => 'no',
	],
];
