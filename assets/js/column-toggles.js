/**
 * PerfLocale - Screen Options "Language Columns" on the Strings and
 * Translations screens: a checkbox shows or hides that language's column at
 * once and saves the hidden list over admin-ajax.
 *
 * The fieldset carries the AJAX action (data-perflocale-col-action) and its
 * nonce (data-perflocale-col-nonce); a checkbox with data-meta-key sends that
 * key too.
 */
( function () {
	'use strict';

	document.querySelectorAll( 'fieldset[data-perflocale-col-action]' ).forEach( function ( fieldset ) {
		var action = fieldset.getAttribute( 'data-perflocale-col-action' );
		var nonce = fieldset.getAttribute( 'data-perflocale-col-nonce' );
		var boxes = fieldset.querySelectorAll( '.perflocale-col-toggle' );

		boxes.forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				var hidden = [];
				boxes.forEach( function ( c ) {
					if ( ! c.checked ) {
						hidden.push( c.value );
					}
				} );

				var fd = new FormData();
				fd.append( 'action', action );
				fd.append( '_wpnonce', nonce );
				var metaKey = cb.getAttribute( 'data-meta-key' );
				if ( metaKey ) {
					fd.append( 'meta_key', metaKey );
				}
				fd.append( 'hidden', JSON.stringify( hidden ) );
				fetch( window.ajaxurl, { method: 'POST', body: fd } );

				var slug = this.value;
				document.querySelectorAll( "[data-perflocale-lang-col='" + slug + "']" ).forEach( function ( el ) {
					el.style.display = cb.checked ? '' : 'none';
				} );
			} );
		} );
	} );
}() );
