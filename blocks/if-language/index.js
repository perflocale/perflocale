/**
 * PerfLocale - Language-Conditional Content block (editor).
 *
 * Block metadata (name, attributes, supports, etc.) lives in block.json
 * and is registered on the PHP side via register_block_type( __DIR__ ).
 * This file provides only the editor-side edit/save implementation.
 *
 * Pure hand-written JS against the global wp.* runtime - no JSX, no
 * build step - to match the plugin's existing zero-build convention.
 *
 * @package PerfLocale
 */

/* global wp, perflocaleConditional */
( function () {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var ToggleControl = wp.components.ToggleControl;
	var CheckboxControl = wp.components.CheckboxControl;
	var Notice = wp.components.Notice;
	var Fragment = wp.element.Fragment;
	var el = wp.element.createElement;
	var __ = wp.i18n.__;

	var languageChoices = ( window.perflocaleConditional && Array.isArray( window.perflocaleConditional.languages ) )
		? window.perflocaleConditional.languages
		: [];

	registerBlockType( 'perflocale/if-language', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var selected = Array.isArray( attributes.languages ) ? attributes.languages : [];
			var invert = !! attributes.invert;

			var toggleLanguage = function ( slug, checked ) {
				var next;

				if ( checked ) {
					next = selected.indexOf( slug ) === -1 ? selected.concat( [ slug ] ) : selected;
				} else {
					next = selected.filter( function ( s ) { return s !== slug; } );
				}

				setAttributes( { languages: next } );
			};

			var inspector = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Visibility', 'perflocale' ), initialOpen: true },
					languageChoices.length === 0
						? el( Notice, {
							status: 'warning',
							isDismissible: false
						}, __( 'No active languages yet. Add languages under PerfLocale → Languages first.', 'perflocale' ) )
						: languageChoices.map( function ( lang ) {
							return el( CheckboxControl, {
								key: lang.slug,
								label: lang.label + ' (' + lang.slug + ')',
								checked: selected.indexOf( lang.slug ) !== -1,
								onChange: function ( isChecked ) { toggleLanguage( lang.slug, isChecked ); }
							} );
						} ),
					el( ToggleControl, {
						label: __( 'Invert - show everywhere EXCEPT these languages', 'perflocale' ),
						checked: invert,
						onChange: function ( next ) { setAttributes( { invert: !! next } ); },
						help: invert
							? __( 'Content is hidden for the selected languages and shown to everyone else.', 'perflocale' )
							: __( 'Content is shown only to visitors in the selected languages.', 'perflocale' )
					} )
				)
			);

			var labelParts = selected.map( function ( slug ) {
				var match = languageChoices.find( function ( c ) { return c.slug === slug; } );
				return match ? match.label : slug;
			} );

			var labelText;

			if ( selected.length === 0 ) {
				labelText = __( 'Select one or more languages in the sidebar to enable this block.', 'perflocale' );
			} else if ( invert ) {
				labelText = __( 'Hidden for:', 'perflocale' ) + ' ' + labelParts.join( ', ' );
			} else {
				labelText = __( 'Shown only for:', 'perflocale' ) + ' ' + labelParts.join( ', ' );
			}

			var blockProps = useBlockProps( { className: 'perflocale-conditional-block' } );

			var header = el(
				'div',
				{ className: 'perflocale-conditional-block__header' },
				el( 'span', { className: 'perflocale-conditional-block__icon', 'aria-hidden': 'true' }, '🌐' ),
				el( 'span', { className: 'perflocale-conditional-block__label' }, labelText )
			);

			var inner = el(
				'div',
				{ className: 'perflocale-conditional-block__inner' },
				el( InnerBlocks, {
					template: [ [ 'core/paragraph', { placeholder: __( 'Write content that only shows for the selected languages…', 'perflocale' ) } ] ],
					renderAppender: InnerBlocks.ButtonBlockAppender
				} )
			);

			return el(
				Fragment,
				null,
				inspector,
				el( 'div', blockProps, header, inner )
			);
		},

		save: function () {
			return el( InnerBlocks.Content, null );
		}
	} );
} )();
