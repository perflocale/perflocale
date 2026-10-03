/**
 * PerfLocale — request helper of the bulk tools on Settings → Translation.
 *
 * window.perflocaleBulkFetch( data, state ) posts `data` (a FormData) to
 * admin-ajax.php and resolves with the parsed JSON answer. When a tool answers
 * that imported data is still missing (Bootstrap::guard_bulk_tool(), code
 * `perflocale_unimported_source`), it asks the operator once per click and
 * resends with `continue_unimported`, which the tool's follow-up requests keep
 * through `state.go`.
 */
window.perflocaleBulkFetch = function ( data, state ) {
	var send = function () {
		return fetch( ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } );
	};
	if ( state.go ) { data.append( 'continue_unimported', '1' ); }
	return send().then( function ( resp ) {
		if ( resp && ! resp.success && resp.data && resp.data.code === 'perflocale_unimported_source' && ! state.go
			&& window.confirm( resp.data.message + '\n\n' + ( resp.data.confirm || '' ) ) ) {
			state.go = true;
			data.append( 'continue_unimported', '1' );
			return send();
		}
		return resp;
	} );
};
