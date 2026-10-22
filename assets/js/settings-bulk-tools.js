/**
 * PerfLocale - the three bulk tools on Settings > Translation:
 * Create Taxonomy Translations, Assign Default Language to Unlinked Posts and
 * Generate Missing Translations. Each button runs its admin-ajax action in
 * batches through window.perflocaleBulkFetch (admin-bulk-fetch.js) and shows
 * a progress bar, a status line and a result table.
 *
 * Data: window.perflocaleTranslationData (wp_localize_script): the three
 * nonces and the screen's strings. Every AJAX value and string written into
 * innerHTML goes through esc().
 */
( function () {
	'use strict';

	var d = window.perflocaleTranslationData;

	if ( ! d ) {
		return;
	}

	var esc = function ( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	};

	// Create Taxonomy Translations.
	( function () {
		var btn = document.getElementById( 'perflocale-create-tax-translations' );
		if ( ! btn ) {
			return;
		}
		btn.addEventListener( 'click', function () {
			var st = {};
			var progress = document.getElementById( 'perflocale-tax-progress' );
			var bar = document.getElementById( 'perflocale-tax-bar' );
			var status = document.getElementById( 'perflocale-tax-status' );
			var percent = document.getElementById( 'perflocale-tax-percent' );
			var result = document.getElementById( 'perflocale-tax-result' );
			btn.disabled = true;
			if ( progress ) progress.style.display = 'block';
			if ( bar ) { bar.style.width = '0'; bar.style.background = '#2271b1'; }
			if ( status ) status.textContent = d.i18nCreating;
			if ( percent ) percent.textContent = '';
			if ( result ) result.innerHTML = '';
			var pct = 0;
			var pInterval = setInterval( function () {
				pct = Math.min( pct + Math.random() * 8, 90 );
				if ( bar ) bar.style.width = pct + '%';
				if ( percent ) percent.textContent = Math.round( pct ) + '%';
			}, 400 );
			var cursorTax = 0, cursorOffset = 0;
			var run = function () {
				var data = new FormData();
				data.append( 'action', 'perflocale_create_taxonomy_translations' );
				data.append( '_nonce', d.taxNonce );
				data.append( 'tax_index', cursorTax );
				data.append( 'term_offset', cursorOffset );
				perflocaleBulkFetch( data, st )
					.then( function ( resp ) {
						clearInterval( pInterval );
						if ( bar ) bar.style.width = '100%';
						if ( percent ) percent.textContent = '100%';
						btn.disabled = false;
						if ( ! resp.success ) {
							if ( bar ) bar.style.background = '#d63638';
							if ( status ) status.textContent = d.i18nFailed;
							if ( result ) result.innerHTML = '<p style="color:#d63638;margin:0;">' + esc( resp.data && resp.data.message ? resp.data.message : d.i18nFailedDot ) + '</p>';
							return;
						}
						if ( resp.data && resp.data.more ) {
							cursorTax = resp.data.tax_index; cursorOffset = resp.data.term_offset;
							if ( status ) status.textContent = resp.data.message || d.i18nCreating;
							pct = 0;
							pInterval = setInterval( function () {
								pct = Math.min( pct + Math.random() * 10, 90 );
								if ( bar ) bar.style.width = pct + '%'; if ( percent ) percent.textContent = Math.round( pct ) + '%';
							}, 400 );
							btn.disabled = true;
							run();
							return;
						}
						if ( bar ) bar.style.background = '#00a32a';
						if ( status ) status.textContent = resp.data.message || d.i18nDone;
						var details = resp.data.taxonomy_details;
						if ( details && details.length ) {
							var html = '<table class="widefat striped" style="max-width:420px;margin-top:6px;">';
							html += '<thead><tr><th style="padding:6px 8px;">' + esc( d.i18nTaxonomy ) + '</th>';
							html += '<th style="padding:6px 8px;text-align:center;">' + esc( d.i18nCreated ) + '</th>';
							html += '<th style="padding:6px 8px;text-align:center;">' + esc( d.i18nExisted ) + '</th></tr></thead><tbody>';
							details.forEach( function ( row ) {
								html += '<tr><td style="padding:4px 8px;">' + esc( row.taxonomy ) + '</td>';
								html += '<td style="padding:4px 8px;text-align:center;color:#00a32a;font-weight:500;">' + esc( row.created ) + '</td>';
								html += '<td style="padding:4px 8px;text-align:center;color:#6b7280;">' + esc( row.skipped ) + '</td></tr>';
							} );
							html += '</tbody></table>';
							if ( result ) result.innerHTML = html;
						}
					} )
					.catch( function () {
						clearInterval( pInterval );
						if ( bar ) { bar.style.width = '100%'; bar.style.background = '#d63638'; }
						if ( status ) status.textContent = d.i18nNetworkError;
						if ( percent ) percent.textContent = '';
						btn.disabled = false;
					} );
			};
			run();
		} );
	}() );

	// Assign Default Language to Unlinked Posts: the same progress pattern,
	// another action and other nodes.
	( function () {
		var btn = document.getElementById( 'perflocale-assign-post-langs' );
		if ( ! btn ) {
			return;
		}
		btn.addEventListener( 'click', function () {
			var st = {};
			var progress = document.getElementById( 'perflocale-postlang-progress' );
			var bar = document.getElementById( 'perflocale-postlang-bar' );
			var status = document.getElementById( 'perflocale-postlang-status' );
			var percent = document.getElementById( 'perflocale-postlang-percent' );
			var result = document.getElementById( 'perflocale-postlang-result' );
			btn.disabled = true;
			if ( progress ) progress.style.display = 'block';
			if ( bar ) { bar.style.width = '0'; bar.style.background = '#2271b1'; }
			if ( status ) status.textContent = d.i18nAssigning;
			if ( percent ) percent.textContent = '';
			if ( result ) result.innerHTML = '';
			var pct = 0;
			var pInterval = setInterval( function () {
				pct = Math.min( pct + Math.random() * 10, 90 );
				if ( bar ) bar.style.width = pct + '%';
				if ( percent ) percent.textContent = Math.round( pct ) + '%';
			}, 400 );
			var run = function () {
				var data = new FormData();
				data.append( 'action', 'perflocale_assign_post_languages' );
				data.append( '_nonce', d.postLangNonce );
				perflocaleBulkFetch( data, st )
					.then( function ( resp ) {
						clearInterval( pInterval );
						if ( bar ) bar.style.width = '100%';
						if ( percent ) percent.textContent = '100%';
						btn.disabled = false;
						if ( ! resp.success ) {
							if ( bar ) bar.style.background = '#d63638';
							if ( status ) status.textContent = d.i18nFailed;
							if ( result ) result.innerHTML = '<p style="color:#d63638;margin:0;">' + esc( resp.data && resp.data.message ? resp.data.message : d.i18nFailedDot ) + '</p>';
							return;
						}
						if ( resp.data && resp.data.remaining > 0 ) {
							if ( status ) status.textContent = resp.data.message || d.i18nAssigning;
							pct = 0;
							pInterval = setInterval( function () {
								pct = Math.min( pct + Math.random() * 10, 90 );
								if ( bar ) bar.style.width = pct + '%'; if ( percent ) percent.textContent = Math.round( pct ) + '%';
							}, 400 );
							btn.disabled = true;
							run();
							return;
						}
						if ( bar ) bar.style.background = '#00a32a';
						if ( status ) status.textContent = resp.data.message || d.i18nDone;
						var details = resp.data.post_type_details;
						if ( details && details.length ) {
							var html = '<table class="widefat striped" style="max-width:420px;margin-top:6px;">';
							html += '<thead><tr><th style="padding:6px 8px;">' + esc( d.i18nPostType ) + '</th>';
							html += '<th style="padding:6px 8px;text-align:center;">' + esc( d.i18nAssigned ) + '</th></tr></thead><tbody>';
							details.forEach( function ( row ) {
								html += '<tr><td style="padding:4px 8px;">' + esc( row.post_type ) + '</td>';
								html += '<td style="padding:4px 8px;text-align:center;color:#00a32a;font-weight:500;">' + esc( row.assigned ) + '</td></tr>';
							} );
							html += '</tbody></table>';
							if ( result ) result.innerHTML = html;
						}
					} )
					.catch( function () {
						clearInterval( pInterval );
						if ( bar ) { bar.style.width = '100%'; bar.style.background = '#d63638'; }
						if ( status ) status.textContent = d.i18nNetworkError;
						if ( percent ) percent.textContent = '';
						btn.disabled = false;
					} );
			};
			run();
		} );
	}() );

	// Generate Missing Translations.
	( function () {
		var btn = document.getElementById( 'perflocale-generate-translations' );
		if ( ! btn ) {
			return;
		}
		var wrap = document.getElementById( 'perflocale-gentr-progress' );
		var bar = document.getElementById( 'perflocale-gentr-bar' );
		var status = document.getElementById( 'perflocale-gentr-status' );
		var percent = document.getElementById( 'perflocale-gentr-percent' );
		var result = document.getElementById( 'perflocale-gentr-result' );
		btn.addEventListener( 'click', function () {
			var cursorType = 0, cursorOffset = 0, pInterval = null, pct = 0, st = {};
			var post = function ( extra ) {
				var data = new FormData();
				data.append( 'action', 'perflocale_generate_missing_translations' );
				data.append( '_nonce', d.genTrNonce );
				Object.keys( extra ).forEach( function ( k ) { data.append( k, extra[ k ] ); } );
				return perflocaleBulkFetch( data, st );
			};
			// Count first. This creates posts; the operator sees the scale and
			// agrees to it before anything is written.
			btn.disabled = true;
			if ( result ) result.innerHTML = '';
			post( { mode: 'count' } ).then( function ( resp ) {
				btn.disabled = false;
				if ( ! resp.success ) { if ( result ) result.innerHTML = '<p style="color:#d63638;margin:0;">' + esc( ( resp.data && resp.data.message ) || d.i18nFailedDot ) + '</p>'; return; }
				if ( ! resp.data.max ) { if ( result ) result.innerHTML = '<p style="margin:0;">' + esc( resp.data.message ) + '</p>'; return; }
				if ( ! window.confirm( resp.data.message ) ) { return; }
				btn.disabled = true;
				if ( wrap ) wrap.style.display = 'block';
				if ( bar ) { bar.style.width = '0'; bar.style.background = '#2271b1'; }
				if ( status ) status.textContent = d.i18nCreating;
				pInterval = setInterval( function () { pct = Math.min( pct + Math.random() * 10, 90 ); if ( bar ) bar.style.width = pct + '%'; if ( percent ) percent.textContent = Math.round( pct ) + '%'; }, 400 );
				var run = function () {
					post( { type_index: cursorType, post_offset: cursorOffset } ).then( function ( r2 ) {
						if ( ! r2.success ) { clearInterval( pInterval ); btn.disabled = false; if ( bar ) bar.style.background = '#d63638'; if ( status ) status.textContent = d.i18nFailed; if ( result ) result.innerHTML = '<p style="color:#d63638;margin:0;">' + esc( ( r2.data && r2.data.message ) || d.i18nFailedDot ) + '</p>'; return; }
						if ( r2.data.more ) { cursorType = r2.data.type_index; cursorOffset = r2.data.post_offset; if ( status ) status.textContent = r2.data.message; run(); return; }
						clearInterval( pInterval );
						if ( bar ) { bar.style.width = '100%'; bar.style.background = '#00a32a'; }
						if ( percent ) percent.textContent = '100%';
						btn.disabled = false;
						if ( status ) status.textContent = r2.data.message || d.i18nDone;
					} ).catch( function () { clearInterval( pInterval ); btn.disabled = false; if ( bar ) bar.style.background = '#d63638'; if ( status ) status.textContent = d.i18nNetworkError; } );
				};
				run();
			} ).catch( function () { btn.disabled = false; if ( status ) status.textContent = d.i18nNetworkError; } );
		} );
	}() );
}() );
