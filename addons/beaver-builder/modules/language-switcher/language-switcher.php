<?php
/**
 * PerfLocale Language Switcher module for Beaver Builder.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Language Switcher Beaver Builder module.
 *
 * Renders the PerfLocale language switcher as a drag-and-drop
 * Beaver Builder module with all configuration options.
 */
class PerfLocale_BB_Language_Switcher extends \FLBuilderModule {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			[
				'name'            => __( 'Language Switcher', 'perflocale' ),
				'description'     => __( 'Display a language switcher for visitors.', 'perflocale' ),
				'category'        => __( 'PerfLocale', 'perflocale' ),
				'icon'            => 'button.svg',
				'partial_refresh' => true,
				'dir'             => __DIR__ . '/',
				'url'             => plugin_dir_url( __FILE__ ),
			]
		);
	}

	/**
	 * Render the module by including the frontend template.
	 *
	 * @return void
	 */
	public function render(): void {
		$module   = $this; // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- consumed by template
		$settings = $this->settings; // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- consumed by template

		include __DIR__ . '/includes/frontend.php';
	}
}

\FLBuilder::register_module(
	'PerfLocale_BB_Language_Switcher',
	[
		'general' => [
			'title'    => __( 'Settings', 'perflocale' ),
			'sections' => [
				'display' => [
					'title'  => __( 'Display', 'perflocale' ),
					'fields' => [
						'style'          => [
							'type'    => 'select',
							'label'   => __( 'Style', 'perflocale' ),
							'default' => 'flags_names',
							'options' => [
								'flags_names' => __( 'Flags + Names', 'perflocale' ),
								'flags_only'  => __( 'Flags Only', 'perflocale' ),
								'names_only'  => __( 'Names Only', 'perflocale' ),
							],
						],
						'display'        => [
							'type'    => 'select',
							'label'   => __( 'Display', 'perflocale' ),
							'default' => 'inline',
							'options' => [
								'inline'   => __( 'Inline', 'perflocale' ),
								'simple'   => __( 'Simple', 'perflocale' ),
								'dropdown' => __( 'Dropdown', 'perflocale' ),
							],
						],
						'layout'         => [
							'type'    => 'select',
							'label'   => __( 'Layout', 'perflocale' ),
							'default' => 'horizontal',
							'options' => [
								'horizontal' => __( 'Horizontal', 'perflocale' ),
								'vertical'   => __( 'Vertical', 'perflocale' ),
							],
						],
						'name_format'    => [
							'type'    => 'select',
							'label'   => __( 'Name Format', 'perflocale' ),
							'default' => 'native',
							'options' => [
								'native'  => __( 'Native', 'perflocale' ),
								'english' => __( 'English', 'perflocale' ),
								'both'    => __( 'Both', 'perflocale' ),
								'slug'    => __( 'Language Code', 'perflocale' ),
							],
						],
						'trigger_format' => [
							'type'    => 'select',
							'label'   => __( 'Trigger Label Format (Dropdown only)', 'perflocale' ),
							'default' => '',
							'options' => [
								''        => __( 'Use site default', 'perflocale' ),
								'inherit' => __( 'Match Name Format', 'perflocale' ),
								'native'  => __( 'Native', 'perflocale' ),
								'english' => __( 'English', 'perflocale' ),
								'both'    => __( 'Both', 'perflocale' ),
								'slug'    => __( 'Language Code', 'perflocale' ),
							],
						],
						'arrow_style'    => [
							'type'    => 'select',
							'label'   => __( 'Dropdown Arrow (Dropdown only)', 'perflocale' ),
							'default' => '',
							'options' => [
								''       => __( 'Use site default', 'perflocale' ),
								'single' => __( 'Single chevron', 'perflocale' ),
								'double' => __( 'Double chevrons', 'perflocale' ),
								'none'   => __( 'No arrow', 'perflocale' ),
							],
						],
					],
				],
				'options' => [
					'title'  => __( 'Options', 'perflocale' ),
					'fields' => [
						'hide_current'      => [
							'type'    => 'select',
							'label'   => __( 'Hide Current Language', 'perflocale' ),
							'default' => 'no',
							'options' => [
								'no'  => __( 'No', 'perflocale' ),
								'yes' => __( 'Yes', 'perflocale' ),
							],
						],
						'show_untranslated' => [
							'type'    => 'select',
							'label'   => __( 'Show Untranslated Languages', 'perflocale' ),
							'default' => 'no',
							'options' => [
								'no'  => __( 'No', 'perflocale' ),
								'yes' => __( 'Yes', 'perflocale' ),
							],
						],
						'font_size'         => [
							'type'    => 'unit',
							'label'   => __( 'Font Size', 'perflocale' ),
							'default' => '14',
							'units'   => [ 'px' ],
						],
						'flag_size'         => [
							'type'    => 'unit',
							'label'   => __( 'Flag Size', 'perflocale' ),
							'default' => '20',
							'units'   => [ 'px' ],
						],
						'gap'               => [
							'type'    => 'unit',
							'label'   => __( 'Gap', 'perflocale' ),
							'default' => '8',
							'units'   => [ 'px' ],
						],
						'custom_class'      => [
							'type'        => 'text',
							'label'       => __( 'Custom CSS Class', 'perflocale' ),
							'default'     => '',
							'placeholder' => __( 'e.g. my-switcher', 'perflocale' ),
						],
					],
				],
			],
		],
	]
);
