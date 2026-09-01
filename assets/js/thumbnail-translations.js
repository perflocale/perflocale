/**
 * PerfLocale - Per-language featured-image metabox picker.
 *
 * Opens the WP media library, stores the picked attachment ID in the
 * hidden input for that language, and updates the inline preview.
 * No DOM build, no AJAX - the save happens with the post save.
 *
 * @package PerfLocale
 */

/* global wp, perflocaleThumbTrans */
( function () {
	'use strict';

	// Every visible label comes from perflocaleThumbTrans, never from
	// wp.i18n.__() - the handle declares no 'wp-i18n' dependency at all.
	// MediaTranslationManager::enqueue_media_picker_on_post_edit() localises
	// all seven keys read below. They could not come from a JS language pack
	// anyway: the string extractor cannot follow a variable into __(), so
	// labels fetched that way never reach this handle's JS translation set
	// and resolve to their English msgid - a row the editor picks or clears
	// would flip to English while the PHP-rendered rows beside it stayed
	// translated. The localised object reuses the very msgids the metabox
	// already translates server-side. The literals below are the last-resort
	// fallback for a page where the localised data failed to print at all.
	var cfg = window.perflocaleThumbTrans || {};

	function refreshRow( row, attachmentId, previewUrl ) {
		var hidden = row.querySelector( 'input[type="hidden"]' );
		if ( hidden ) {
			hidden.value = attachmentId || '';
		}

		var preview = row.querySelector( '.perflocale-thumb-translations__preview' );
		var state   = row.querySelector( '.perflocale-thumb-translations__state' );
		var pick    = row.querySelector( '.perflocale-thumb-translations__pick' );

		if ( previewUrl && preview ) {
			preview.style.backgroundImage = 'url(' + previewUrl + ')';
		}

		if ( attachmentId ) {
			if ( state ) state.textContent = cfg.using_override || 'Using override';
			if ( pick )  pick.textContent  = cfg.button_change || 'Change';

			// Add a "Remove" button if not already there.
			if ( pick && ! row.querySelector( '.perflocale-thumb-translations__clear' ) ) {
				var clearBtn = document.createElement( 'button' );
				clearBtn.type       = 'button';
				clearBtn.className  = 'button-link-delete perflocale-thumb-translations__clear';
				clearBtn.setAttribute( 'data-lang', row.dataset.lang || '' );
				clearBtn.style.textAlign = 'right';
				clearBtn.style.fontSize  = '11px';
				clearBtn.textContent = cfg.button_remove || 'Remove';
				pick.parentNode.insertBefore( clearBtn, pick.nextSibling );
			}
		} else {
			// Drop the removed override's thumbnail so the tile stops
			// contradicting the "Using default" label; the CSS placeholder
			// colour shows through until the post is saved.
			if ( preview ) preview.style.backgroundImage = '';

			if ( state ) state.textContent = cfg.using_default || 'Using default';
			if ( pick )  pick.textContent  = cfg.button_set || 'Set';

			var existing = row.querySelector( '.perflocale-thumb-translations__clear' );
			if ( existing ) existing.remove();
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var pick = e.target.closest( '.perflocale-thumb-translations__pick' );
		if ( ! pick ) return;
		e.preventDefault();

		var row = pick.closest( '.perflocale-thumb-translations__row' );
		if ( ! row ) return;

		var frame = wp.media( {
			title:    cfg.pick_title  || 'Select featured image',
			library:  { type: 'image' },
			button:   { text: cfg.pick_button || 'Use this image' },
			multiple: false
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var previewUrl = ( attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url ) || attachment.url;
			refreshRow( row, attachment.id, previewUrl );
		} );

		frame.open();
	} );

	document.addEventListener( 'click', function ( e ) {
		var clearBtn = e.target.closest( '.perflocale-thumb-translations__clear' );
		if ( ! clearBtn ) return;
		e.preventDefault();

		var row = clearBtn.closest( '.perflocale-thumb-translations__row' );
		if ( ! row ) return;

		refreshRow( row, '', '' );
	} );

} )();
