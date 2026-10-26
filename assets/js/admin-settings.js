/**
 * PerfLocale - admin Settings page progressive enhancements.
 *
 * Switcher tab: rows tagged with `data-perflocale-show-when-display="<value>"`
 * are shown only when the Display Mode select matches `<value>`. No-ops on
 * tabs where the trigger select isn't present.
 *
 * URL & Routing tab: the per-language domain table
 * (`#perflocale-domain-config`) is shown only while the "Per-language domain"
 * URL mode is selected. The server renders it in every mode, hidden outside
 * domain mode, so a save keeps the stored domains; this only follows the
 * radio. No-ops on tabs without the URL mode radios.
 *
 * URL & Routing tab: the GeoIP rows (`.perflocale-geo-field`) follow the
 * GeoIP checkbox (`#perflocale-geo-enabled`).
 *
 * Export & Import tab: "Select All" (`#perflocale-export-all`) ticks or
 * clears every export section, and follows them when they change. The import
 * form's upload in pieces is the first function below.
 *
 * Translation tab: the three bulk tools are the last function below.
 *
 * Clipboard / confirm / submit-busy handlers for data-perflocale-* attrs
 * live in admin-actions.js (delegated, loaded on every plugin admin page).
 */

/*
 * Export & Import tab: data import upload in pieces. A file that PHP accepts
 * in one request is submitted with the form as before. A larger one, up to
 * the importer's own limit, is sent to the admin-ajax action
 * `perflocale_import_upload` in pieces (start, then each piece in order); the
 * form is then submitted with the upload's token instead of the file, and the
 * server imports the assembled copy exactly as an uploaded file. A piece
 * refused as too large or whose answer was lost is sent again (send()). A file
 * above the importer's limit is refused before anything is sent, with the
 * WP-CLI command to use instead.
 * Without this code the form works as before, up to PHP's limit.
 *
 * Once a file is chosen, the form's add-on data choices show only the ones
 * the file carries, read from the file's first bytes.
 *
 * Every value it needs is on the form (data-* attributes and the form's own
 * nonce field); nothing is printed into the page as a script.
 */
( function () {
	'use strict';

	function init( form ) {
		var input  = form.querySelector( 'input[type="file"][name="perflocale_import_file"]' );
		var token  = form.querySelector( 'input[name="perflocale_import_upload"]' );
		var nonce  = form.querySelector( 'input[name="_wpnonce"]' );
		var status = form.querySelector( '[data-perflocale-import-status]' );
		var single = parseInt( form.getAttribute( 'data-single-limit' ), 10 ) || 0;
		var limit  = parseInt( form.getAttribute( 'data-import-limit' ), 10 ) || 0;
		var ajax   = form.getAttribute( 'data-ajax-url' ) || '';
		var busy   = false;

		if ( ! input || ! token || ! nonce || ! ajax || ! window.fetch || ! window.FormData ) {
			return;
		}

		function say( text ) {
			if ( ! status ) {
				return;
			}
			status.textContent = text;
			status.hidden      = ( text === '' );
		}

		function message( attr, value ) {
			var text = form.getAttribute( attr ) || '';
			return text.replace( '%d', String( value ) ).replace( '%%', '%' ).replace( '%s', String( value ) );
		}

		// A failed request rejects with an Error that carries the HTTP status
		// (`status`), or `network` when no response arrived at all.
		function post( fields, piece ) {
			var body = new FormData();

			body.append( 'action', 'perflocale_import_upload' );
			body.append( '_wpnonce', nonce.value );

			Object.keys( fields ).forEach( function ( key ) {
				body.append( key, String( fields[ key ] ) );
			} );

			if ( piece ) {
				body.append( 'piece', piece, 'piece.bin' );
			}

			return fetch( ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
				.catch( function () {
					var lost = new Error( form.getAttribute( 'data-msg-network' ) );
					lost.network = true;
					throw lost;
				} )
				.then( function ( response ) {
					return response.json().catch( function () {
						return { success: false, data: { message: '' } };
					} ).then( function ( json ) {
						if ( ! json || ! json.success ) {
							var failure = new Error( json && json.data && json.data.message ? json.data.message : form.getAttribute( 'data-msg-network' ) );
							failure.status = response.status;
							throw failure;
						}
						return json.data || {};
					} );
				} );
		}

		function upload( file ) {
			var started = null;
			var minimum = parseInt( form.getAttribute( 'data-min-piece' ), 10 ) || 262144;

			return post( { step: 'start', size: file.size } )
				.then( function ( data ) {
					var size = parseInt( data.piece, 10 ) || minimum;
					var busyRetries = 0;
					var lostRetries = 0;

					started = data.token;

					function later( from ) {
						return new Promise( function ( resolve ) {
							setTimeout( resolve, 3000 );
						} ).then( function () {
							return send( from );
						} );
					}

					// Sends the pieces from `from` in order. A 413 or a lost answer
					// (no response, 502/504/524) resends the piece halved at once; a
					// lost one at the smallest piece is resent up to 3 times, paused.
					function send( from ) {
						if ( from >= file.size ) {
							return Promise.resolve();
						}

						var to = Math.min( from + size, file.size );

						say( message( 'data-msg-progress', Math.floor( ( from / file.size ) * 100 ) ) );

						return post( { step: 'piece', token: started, offset: from }, file.slice( from, to ) ).then(
							function () {
								lostRetries = 0;
								return send( to );
							},
							function ( error ) {
								var lost     = error && ( error.network || error.status === 502 || error.status === 504 || error.status === 524 );
								var tooLarge = lost || ( error && error.status === 413 );

								// 503: the server was busy and kept the file; the
								// same piece is sent again after a pause.
								if ( error && error.status === 503 && busyRetries < 10 ) {
									busyRetries++;
									return later( from );
								}

								if ( tooLarge && size > minimum ) {
									size = Math.max( minimum, Math.floor( size / 2 ) );
									return send( from );
								}

								if ( lost && lostRetries < 3 ) {
									lostRetries++;
									return later( from );
								}

								if ( error && error.status === 413 ) {
									throw new Error( form.getAttribute( 'data-msg-piece-refused' ) || error.message );
								}

								throw error;
							}
						);
					}

					return send( 0 ).then( function () {
						say( message( 'data-msg-progress', 100 ) );
						return started;
					} );
				} )
				.catch( function ( error ) {
					if ( started ) {
						post( { step: 'abort', token: started } ).catch( function () {} );
					}
					throw error;
				} );
		}

		form.addEventListener( 'submit', function ( event ) {
			if ( busy ) {
				event.preventDefault();
				return;
			}

			token.value = '';

			var file = input.files && input.files.length ? input.files[0] : null;

			// Small enough for one request: the form goes as it always has.
			if ( ! file || single <= 0 || file.size <= single ) {
				return;
			}

			event.preventDefault();

			if ( limit > 0 && file.size > limit ) {
				say( form.getAttribute( 'data-msg-too-large' ) || '' );
				return;
			}

			busy = true;

			upload( file ).then(
				function ( done ) {
					// The file is on the server: send the form with the token
					// and without the file.
					token.value    = done;
					input.disabled = true;
					busy           = false;
					// The form's submit button is named "submit", which hides
					// form.submit(); call the method from the prototype.
					HTMLFormElement.prototype.submit.call( form );
				},
				function ( error ) {
					busy = false;
					say( message( 'data-msg-failed', error && error.message ? error.message : form.getAttribute( 'data-msg-network' ) ) );
				}
			);
		} );
	}

	/**
	 * The value of a top-level member of an export file, read from the
	 * file's first bytes: the exporter writes `sections` and `addon_groups`
	 * at the top. Undefined when the member is not there or does not parse.
	 */
	function member( head, name ) {
		var at = head.indexOf( '"' + name + '":' );
		var i, ch, depth = 0, inString = false, start = -1;

		if ( at < 0 ) {
			return undefined;
		}

		for ( i = at + name.length + 3; i < head.length; i++ ) {
			ch = head.charAt( i );

			if ( inString ) {
				if ( ch === '\\' ) {
					i++;
				} else if ( ch === '"' ) {
					inString = false;
				}
				continue;
			}

			if ( ch === '"' ) {
				inString = true;
			} else if ( ch === '[' || ch === '{' ) {
				if ( start < 0 ) {
					start = i;
				}
				depth++;
			} else if ( ch === ']' || ch === '}' ) {
				depth--;
				if ( depth === 0 && start >= 0 ) {
					try {
						return JSON.parse( head.slice( start, i + 1 ) );
					} catch ( e ) {
						return undefined;
					}
				}
			}
		}

		return undefined;
	}

	/**
	 * The add-on data choices: once a file is chosen, show only the ones the
	 * file carries (all of them when its first bytes do not say).
	 */
	function initAddonChoices( form ) {
		var input    = form.querySelector( 'input[type="file"][name="perflocale_import_file"]' );
		var fieldset = form.querySelector( '[data-perflocale-import-addon]' );

		if ( ! input || ! fieldset || ! window.Blob || ! Blob.prototype.slice || ! window.FileReader ) {
			return;
		}

		var choices = fieldset.querySelectorAll( '[data-perflocale-addon-choice]' );

		function show( carried ) {
			var any = false;

			Array.prototype.forEach.call( choices, function ( label ) {
				var on = ! carried || carried.indexOf( label.getAttribute( 'data-perflocale-addon-choice' ) ) >= 0;
				label.hidden = ! on;
				any = any || on;
			} );

			fieldset.hidden = ! any;
		}

		input.addEventListener( 'change', function () {
			var file = input.files && input.files.length ? input.files[0] : null;

			if ( ! file ) {
				show( null );
				return;
			}

			var reader = new FileReader();

			reader.onload = function () {
				var head     = String( reader.result || '' );
				var sections = member( head, 'sections' );
				var groups   = member( head, 'addon_groups' );
				var carried;

				if ( ! Array.isArray( sections ) ) {
					show( null );
					return;
				}

				carried = sections.indexOf( 'addon_data' ) >= 0 ? [ 'addon_data' ] : [];

				if ( groups && typeof groups === 'object' && ! Array.isArray( groups ) ) {
					carried = carried.concat( Object.keys( groups ) );
				}

				show( carried );
			};

			reader.onerror = function () {
				show( null );
			};

			reader.readAsText( file.slice( 0, 65536 ) );
		} );
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-perflocale-import-form]' ), function ( form ) {
			init( form );
			initAddonChoices( form );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );

( function () {
	'use strict';

	function syncDisplayRows() {
		var displaySelect = document.getElementById( 'perflocale-switcher-display' );

		if ( ! displaySelect ) {
			return;
		}

		var rows = document.querySelectorAll( '[data-perflocale-show-when-display]' );

		function apply() {
			var current = displaySelect.value;

			rows.forEach( function ( row ) {
				var needed = row.getAttribute( 'data-perflocale-show-when-display' );
				row.style.display = ( needed === current ) ? '' : 'none';
			} );
		}

		displaySelect.addEventListener( 'change', apply );
		apply();
	}

	function syncDomainConfig() {
		var radios = document.querySelectorAll( 'input[name="url_mode"]' );
		var config = document.getElementById( 'perflocale-domain-config' );

		if ( ! radios.length || ! config ) {
			return;
		}

		radios.forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				config.style.display = ( this.value === 'domain' ) ? '' : 'none';
			} );
		} );
	}

	function syncGeoFields() {
		var geo = document.getElementById( 'perflocale-geo-enabled' );

		if ( ! geo ) {
			return;
		}

		function toggle() {
			var on = geo.checked;
			document.querySelectorAll( '.perflocale-geo-field' ).forEach( function ( tr ) {
				tr.style.display = on ? '' : 'none';
			} );
		}

		geo.addEventListener( 'change', toggle );
		toggle();
	}

	function syncExportSelectAll() {
		var allCb = document.getElementById( 'perflocale-export-all' );
		var boxes = document.querySelectorAll( '.perflocale-export-section' );

		if ( ! allCb ) {
			return;
		}

		allCb.addEventListener( 'change', function () {
			boxes.forEach( function ( cb ) {
				cb.checked = allCb.checked;
			} );
		} );

		boxes.forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				allCb.checked = [].every.call( boxes, function ( c ) {
					return c.checked;
				} );
			} );
		} );
	}

	function init() {
		syncDisplayRows();
		syncDomainConfig();
		syncGeoFields();
		syncExportSelectAll();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

/*
 * Settings > Translation: the three bulk tools, Create Taxonomy Translations,
 * Assign Default Language to Unlinked Posts and Generate Missing Translations.
 * Each button runs its admin-ajax action in batches through
 * window.perflocaleBulkFetch (admin-bulk-fetch.js, a dependency of this script
 * on that tab) and shows a progress bar, a status line and a result table.
 * Data: window.perflocaleTranslationData (wp_localize_script, that tab only):
 * the three nonces and the screen's strings. Every AJAX value and string
 * written into innerHTML goes through esc().
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
