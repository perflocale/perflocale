/**
 * PerfLocale - Strings screen: after a PO import or export notice has
 * rendered, drop its query arguments from the address bar, so a reload does
 * not show the notice again and pagination links stay clean.
 */
( function () {
	'use strict';

	if ( ! window.history || ! window.history.replaceState ) {
		return;
	}

	var url = new URL( window.location.href );

	[ 'po_message', 'po_upload_err', 'imported', 'skipped', 'errors' ].forEach( function ( key ) {
		url.searchParams.delete( key );
	} );

	window.history.replaceState( {}, document.title, url.toString() );
}() );
