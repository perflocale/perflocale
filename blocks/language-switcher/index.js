/**
 * PerfLocale - Language Switcher block (editor).
 *
 * Metadata (name, attributes, supports) lives in block.json. Attribute
 * defaults are replaced at register-time by the plugin's own switcher
 * settings via the `register_block_type_args` filter in PHP, so a
 * freshly-inserted block always reflects the site's global switcher
 * configuration - the user only overrides per-instance when they want
 * to diverge from the site default.
 *
 * The edit UI mirrors the Settings → Switcher tab field-by-field so
 * authors don't need to learn two different vocabularies. Every control
 * writes the same attribute the server-side renderer reads, and the
 * live preview is a ServerSideRender that picks up every change.
 *
 * @package PerfLocale
 */

/* global wp */
( function () {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var ServerSideRender = wp.serverSideRender;
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var RangeControl = wp.components.RangeControl;
	// Disabled renders its children as a display-only tree (pointer-events
	// disabled, interactives untabbable). Needed here so the language link
	// `<a>`s in the ServerSideRender preview aren't clickable inside the
	// editor - clicking them tried to navigate the editor frame and
	// cascaded into a React ResizeObserver crash ("Cannot read properties
	// of null (reading 'ResizeObserver')") + a 401 block_cannot_read REST
	// error on the follow-up re-render.
	var Disabled = wp.components.Disabled;

	registerBlockType( 'perflocale/language-switcher', {
		edit: function ( props ) {
			var attrs = props.attributes;
			var set = props.setAttributes;

			// Note: `style` used to conflate "Dropdown" (a display mode) into
			// the style list. This split mirrors the Settings → Switcher
			// page exactly so the block renderer receives the right values.
			var styleOptions = [
				{ value: 'flags_names', label: __( 'Flags + Names', 'perflocale' ) },
				{ value: 'flags_only', label: __( 'Flags Only', 'perflocale' ) },
				{ value: 'names_only', label: __( 'Names Only', 'perflocale' ) }
			];

			var displayOptions = [
				{ value: 'inline', label: __( 'Inline', 'perflocale' ) },
				{ value: 'simple', label: __( 'Simple', 'perflocale' ) },
				{ value: 'dropdown', label: __( 'Dropdown', 'perflocale' ) }
			];

			var layoutOptions = [
				{ value: 'horizontal', label: __( 'Horizontal', 'perflocale' ) },
				{ value: 'vertical', label: __( 'Vertical', 'perflocale' ) }
			];

			var nameFormatOptions = [
				{ value: 'native', label: __( 'Native (e.g. Deutsch)', 'perflocale' ) },
				{ value: 'english', label: __( 'English (e.g. German)', 'perflocale' ) },
				{ value: 'both', label: __( 'Both (Deutsch / German)', 'perflocale' ) },
				{ value: 'slug', label: __( 'Code only (DE)', 'perflocale' ) }
			];

			// Trigger label format on dropdown switchers. `inherit` keeps
			// trigger and options in sync (the common case). The other
			// values let a header pill show "EN" while the dropdown list
			// keeps full names — useful when nav-bar real estate is tight.
			var triggerFormatOptions = [
				{ value: 'inherit', label: __( 'Match options format', 'perflocale' ) },
				{ value: 'native',  label: __( 'Native (e.g. Deutsch)', 'perflocale' ) },
				{ value: 'english', label: __( 'English (e.g. German)', 'perflocale' ) },
				{ value: 'both',    label: __( 'Both (Deutsch / German)', 'perflocale' ) },
				{ value: 'slug',    label: __( 'Code only (DE)', 'perflocale' ) }
			];

			var untranslatedLinkOptions = [
				{ value: 'homepage', label: __( 'Language Homepage', 'perflocale' ) },
				{ value: 'no_link', label: __( 'Show Without Link', 'perflocale' ) },
				{ value: 'hide', label: __( 'Hide Link', 'perflocale' ) }
			];

			var arrowStyleOptions = [
				{ value: 'single', label: __( 'Single arrow (down chevron)', 'perflocale' ) },
				{ value: 'double', label: __( 'Double arrow (up + down chevrons)', 'perflocale' ) },
				{ value: 'none',   label: __( 'No arrow', 'perflocale' ) }
			];

			var inspectorControls = el( InspectorControls, null,
				el( PanelBody, { title: __( 'Display', 'perflocale' ), initialOpen: true },
					el( SelectControl, {
						label: __( 'Switcher Style', 'perflocale' ),
						help: __( 'What to show for each language in the switcher.', 'perflocale' ),
						value: attrs.style,
						options: styleOptions,
						onChange: function ( v ) { set( { style: v } ); }
					} ),
					el( SelectControl, {
						label: __( 'Display Mode', 'perflocale' ),
						help: __( 'Inline shows all languages with hover effects. Simple shows plain text. Dropdown shows a click-to-expand menu.', 'perflocale' ),
						value: attrs.display,
						options: displayOptions,
						onChange: function ( v ) { set( { display: v } ); }
					} ),
					el( SelectControl, {
						label: __( 'Layout', 'perflocale' ),
						value: attrs.layout,
						options: layoutOptions,
						onChange: function ( v ) { set( { layout: v } ); }
					} ),
					el( SelectControl, {
						label: __( 'Name Format', 'perflocale' ),
						help: __( 'Format used for each language in the dropdown options / inline list.', 'perflocale' ),
						value: attrs.nameFormat,
						options: nameFormatOptions,
						onChange: function ( v ) { set( { nameFormat: v } ); }
					} ),
					// Only meaningful in dropdown mode — inline / simple
					// modes don't have a separate trigger button, so the
					// "trigger" format would be unused. Hidden in those
					// modes to keep the inspector free of dead controls.
					attrs.display === 'dropdown' ? el( SelectControl, {
						label: __( 'Trigger Label Format', 'perflocale' ),
						help: __( 'Format of the visible label on the dropdown button. Set to "Match options format" to keep it in sync with Name Format, or pick a different format for compact headers (e.g. "EN" trigger + "English" options).', 'perflocale' ),
						value: attrs.triggerFormat,
						options: triggerFormatOptions,
						onChange: function ( v ) { set( { triggerFormat: v } ); }
					} ) : null
				),
				el( PanelBody, { title: __( 'Languages', 'perflocale' ), initialOpen: false },
					el( ToggleControl, {
						label: __( 'Show untranslated languages', 'perflocale' ),
						help: __( 'Show languages even when a translation is not available.', 'perflocale' ),
						checked: !! attrs.showUntranslated,
						onChange: function ( v ) { set( { showUntranslated: !! v } ); }
					} ),
					el( ToggleControl, {
						label: __( 'Hide current language', 'perflocale' ),
						help: __( 'Hide the currently active language from the switcher.', 'perflocale' ),
						checked: !! attrs.hideCurrent,
						onChange: function ( v ) { set( { hideCurrent: !! v } ); }
					} ),
					el( SelectControl, {
						label: __( 'Untranslated Link Target', 'perflocale' ),
						help: __( 'Where to link when a translation does not exist for a given language.', 'perflocale' ),
						value: attrs.untranslatedLink,
						options: untranslatedLinkOptions,
						onChange: function ( v ) { set( { untranslatedLink: v } ); }
					} )
				),
				el( PanelBody, { title: __( 'Appearance', 'perflocale' ), initialOpen: false },
					// Arrow style only matters in dropdown mode — inline/
					// simple displays don't render a trigger button. Hide
					// the control in those modes so it isn't misleading.
					attrs.display === 'dropdown' ? el( SelectControl, {
						label: __( 'Dropdown arrow', 'perflocale' ),
						help: __( 'Icon shown next to the language label on the dropdown trigger. Themes can override with the perflocale/switcher/arrow_html filter.', 'perflocale' ),
						value: attrs.arrowStyle,
						options: arrowStyleOptions,
						onChange: function ( v ) { set( { arrowStyle: v } ); }
					} ) : null,
					el( ToggleControl, {
						label: __( 'Show flags', 'perflocale' ),
						checked: !! attrs.showFlags,
						onChange: function ( v ) { set( { showFlags: !! v } ); }
					} ),
					el( ToggleControl, {
						label: __( 'Show names', 'perflocale' ),
						checked: !! attrs.showNames,
						onChange: function ( v ) { set( { showNames: !! v } ); }
					} ),
					el( RangeControl, {
						label: __( 'Font size (px)', 'perflocale' ),
						value: attrs.fontSize,
						min: 10,
						max: 24,
						onChange: function ( v ) { set( { fontSize: v } ); }
					} ),
					el( RangeControl, {
						label: __( 'Flag size (px)', 'perflocale' ),
						value: attrs.flagSize,
						min: 14,
						max: 40,
						onChange: function ( v ) { set( { flagSize: v } ); }
					} ),
					el( RangeControl, {
						label: __( 'Gap (px)', 'perflocale' ),
						value: attrs.gap,
						min: 0,
						max: 24,
						onChange: function ( v ) { set( { gap: v } ); }
					} )
				)
			);

			// useBlockProps must go on the outermost element so Gutenberg's
			// selection / List View / drag-and-drop machinery can recognize
			// the block wrapper. Disabled wraps the preview only - if it
			// wrapped the whole block, clicks would bypass the selection
			// handler and the block would be unselectable by click or
			// List View.
			var blockProps = useBlockProps();

			return el( 'div', blockProps,
				inspectorControls,
				el( Disabled, null,
					el( ServerSideRender, {
						block: 'perflocale/language-switcher',
						attributes: attrs
					} )
				)
			);
		},
		save: function () {
			return null;
		}
	} );
} )();
