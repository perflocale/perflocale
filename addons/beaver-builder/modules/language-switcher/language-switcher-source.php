<?php
/**
 * Copy of language-switcher.php with source strings instead of gettext calls, for requests where Beaver Builder shows no labels.
 *
 * A change to one file is made in both. The slug is given: Beaver Builder otherwise takes it from the file name.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Language Switcher Beaver Builder module.
 */
class PerfLocale_BB_Language_Switcher extends \FLBuilderModule {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			[
				'slug'            => 'language-switcher',
				'name'            => 'Language Switcher',
				'description'     => 'Display a language switcher for visitors.',
				'category'        => 'PerfLocale',
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
			'title'    => 'Settings',
			'sections' => [
				'display' => [
					'title'  => 'Display',
					'fields' => [
						'style'          => [
							'type'    => 'select',
							'label'   => 'Style',
							'default' => 'flags_names',
							'options' => [
								'flags_names' => 'Flags + Names',
								'flags_only'  => 'Flags Only',
								'names_only'  => 'Names Only',
							],
						],
						'display'        => [
							'type'    => 'select',
							'label'   => 'Display',
							'default' => 'inline',
							'options' => [
								'inline'   => 'Inline',
								'simple'   => 'Simple',
								'dropdown' => 'Dropdown',
							],
						],
						'layout'         => [
							'type'    => 'select',
							'label'   => 'Layout',
							'default' => 'horizontal',
							'options' => [
								'horizontal' => 'Horizontal',
								'vertical'   => 'Vertical',
							],
						],
						'name_format'    => [
							'type'    => 'select',
							'label'   => 'Name Format',
							'default' => 'native',
							'options' => [
								'native'  => 'Native',
								'english' => 'English',
								'both'    => 'Both',
								'slug'    => 'Language Code',
							],
						],
						'trigger_format' => [
							'type'    => 'select',
							'label'   => 'Trigger Label Format (Dropdown only)',
							'default' => '',
							'options' => [
								''        => 'Use site default',
								'inherit' => 'Match Name Format',
								'native'  => 'Native',
								'english' => 'English',
								'both'    => 'Both',
								'slug'    => 'Language Code',
							],
						],
						'arrow_style'    => [
							'type'    => 'select',
							'label'   => 'Dropdown Arrow (Dropdown only)',
							'default' => '',
							'options' => [
								''       => 'Use site default',
								'single' => 'Single chevron',
								'double' => 'Double chevrons',
								'none'   => 'No arrow',
							],
						],
					],
				],
				'options' => [
					'title'  => 'Options',
					'fields' => [
						'hide_current'      => [
							'type'    => 'select',
							'label'   => 'Hide Current Language',
							'default' => 'no',
							'options' => [
								'no'  => 'No',
								'yes' => 'Yes',
							],
						],
						'show_untranslated' => [
							'type'    => 'select',
							'label'   => 'Show Untranslated Languages',
							'default' => 'no',
							'options' => [
								'no'  => 'No',
								'yes' => 'Yes',
							],
						],
						'font_size'         => [
							'type'    => 'unit',
							'label'   => 'Font Size',
							'default' => '14',
							'units'   => [ 'px' ],
						],
						'flag_size'         => [
							'type'    => 'unit',
							'label'   => 'Flag Size',
							'default' => '20',
							'units'   => [ 'px' ],
						],
						'gap'               => [
							'type'    => 'unit',
							'label'   => 'Gap',
							'default' => '8',
							'units'   => [ 'px' ],
						],
						'custom_class'      => [
							'type'        => 'text',
							'label'       => 'Custom CSS Class',
							'default'     => '',
							'placeholder' => 'e.g. my-switcher',
						],
					],
				],
			],
		],
	]
);
