/**
 * PerfLocale - Drag-and-drop file uploader.
 *
 * Progressive enhancement around any element marked
 * [data-perflocale-dropzone]. The element wraps an <input type="file"> and
 * (optionally) a [data-perflocale-dropzone-name] node. Without JS, the
 * native file picker still works via the label's click forwarding.
 *
 * Conditionally enqueued by Admin\Assets.php — only on the Strings and
 * Glossary admin screens, since those are the only places the dropzone
 * markup is rendered.
 *
 * @package PerfLocale
 */

( function () {
	'use strict';

	var initialised = new WeakSet();

	function init( zone ) {
		if ( ! zone || initialised.has( zone ) ) { return; }
		initialised.add( zone );

		var input = zone.querySelector( 'input[type="file"]' );
		if ( ! input ) { return; }
		var nameOut = zone.querySelector( '[data-perflocale-dropzone-name]' );

		function setFiles( fileList ) {
			if ( ! fileList || fileList.length === 0 ) { return; }
			try {
				// Modern browsers honour `input.files = …` when assigned a
				// DataTransfer-derived FileList. Cleanest way to forward
				// dropped files into the form payload.
				input.files = fileList;
			} catch ( e ) {
				// Older browsers may throw; the user falls back to the
				// click-to-pick path. No visible error.
			}
			updateName();
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		function updateName() {
			if ( ! nameOut ) { return; }
			if ( input.files && input.files.length > 0 ) {
				nameOut.textContent = input.files[0].name;
			} else {
				nameOut.textContent = '';
			}
		}

		input.addEventListener( 'change', updateName );

		[ 'dragenter', 'dragover' ].forEach( function ( evtName ) {
			zone.addEventListener( evtName, function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				zone.classList.add( 'perflocale-dropzone--active' );
				if ( e.dataTransfer ) { e.dataTransfer.dropEffect = 'copy'; }
			} );
		} );

		[ 'dragleave', 'dragend' ].forEach( function ( evtName ) {
			zone.addEventListener( evtName, function ( e ) {
				// Only deactivate when the cursor leaves the zone itself.
				// dragleave fires for child-element transitions too.
				if ( evtName === 'dragleave' && e.relatedTarget && zone.contains( e.relatedTarget ) ) {
					return;
				}
				zone.classList.remove( 'perflocale-dropzone--active' );
			} );
		} );

		zone.addEventListener( 'drop', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			zone.classList.remove( 'perflocale-dropzone--active' );

			if ( e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0 ) {
				setFiles( e.dataTransfer.files );
			}
		} );

		updateName();
	}

	function initAll( root ) {
		( root || document ).querySelectorAll( '[data-perflocale-dropzone]' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () { initAll(); } );
	} else {
		initAll();
	}

	// Re-scan when a <details> disclosure opens — PO and CSV import forms
	// live inside collapsed <details>, and some browsers don't run picker
	// behaviour until the wrapper expands.
	document.addEventListener( 'toggle', function ( e ) {
		if ( e.target && e.target.matches && e.target.matches( 'details' ) && e.target.open ) {
			initAll( e.target );
		}
	}, true );

} )();
