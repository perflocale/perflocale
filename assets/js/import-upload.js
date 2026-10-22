/**
 * PerfLocale - data import upload in pieces.
 *
 * Settings → Export & Import. A file that PHP accepts in one request is
 * submitted with the form as before. A larger one, up to the importer's own
 * limit, is sent to the admin-ajax action `perflocale_import_upload` in
 * pieces (start, then each piece in order); the form is then submitted with
 * the upload's token instead of the file, and the server imports the
 * assembled copy exactly as an uploaded file. A piece the server or a proxy
 * refuses as too large is sent again at half the size, down to the smallest
 * piece (`data-min-piece`). A file above the importer's
 * limit is refused before anything is sent, with the WP-CLI command to use
 * instead. Without this script the form works as before, up to PHP's limit.
 *
 * Once a file is chosen, the form's add-on data choices show only the ones
 * the file carries, read from the file's first bytes.
 *
 * Every value it needs is on the form (data-* attributes and the form's own
 * nonce field); nothing is printed into the page as a script.
 *
 * @package PerfLocale
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

					started = data.token;

					// Send the piece at `from`, then the rest, in order. A
					// piece the server or a proxy refuses as too large (HTTP
					// 413, or a request cut off with no response) is sent
					// again at half the size, down to the smallest piece, and
					// the smaller size is kept for the rest of the file. The
					// server accepts pieces of any size up to its own, each at
					// the offset where the file ends.
					function send( from ) {
						if ( from >= file.size ) {
							return Promise.resolve();
						}

						var to = Math.min( from + size, file.size );

						say( message( 'data-msg-progress', Math.floor( ( from / file.size ) * 100 ) ) );

						return post( { step: 'piece', token: started, offset: from }, file.slice( from, to ) ).then(
							function () {
								return send( to );
							},
							function ( error ) {
								var tooLarge = error && ( error.status === 413 || error.network );

								// 503: the server was busy and kept the file; the
								// same piece is sent again after a pause.
								if ( error && error.status === 503 && busyRetries < 10 ) {
									busyRetries++;
									return new Promise( function ( resolve ) {
										setTimeout( resolve, 3000 );
									} ).then( function () {
										return send( from );
									} );
								}

								if ( tooLarge && size > minimum ) {
									size = Math.max( minimum, Math.floor( size / 2 ) );
									return send( from );
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
