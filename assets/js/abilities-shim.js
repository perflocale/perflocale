/**
 * PerfLocale — JS-side Abilities API shim.
 *
 * Registers the plugin's six PHP-side abilities with the WordPress JS
 * Abilities API (WP 7.0+) so client-side AI tools and editor extensions
 * (Gutenberg sidebar agents, custom block-toolbar plugins, etc.) can
 * invoke them without hardcoding REST endpoints.
 *
 * Feature-detected: if `window.wp.abilities.register` isn't present
 * (every WP version through 7.0.x at the time of writing), this script
 * is a no-op. The shim activates automatically once core ships the JS
 * side of the API — no plugin update required.
 *
 * NOTE (speculative contract): the JS-side `wp.abilities.register` API is
 * not shipped in any released WordPress yet, so the exact register()
 * signature and whether core expects a caller-supplied `invoke`/`run`
 * callback at all are not final. The REST run route this uses
 * (`/wp-abilities/v1/abilities/{name}/run`) IS shipped and verified in WP
 * 7.0.2. If core ships a different register contract (e.g. it wires the
 * REST transport itself, or names the callback differently), revisit the
 * `register()` payload and runAbility() below. Until then this stays a
 * gated no-op with zero runtime effect — see PERFLOCALE_ABILITIES_JS in
 * the release checklist.
 *
 * @package PerfLocale
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' || ! window.wp ) {
		return;
	}

	var abilitiesApi = window.wp.abilities;

	if ( ! abilitiesApi || typeof abilitiesApi.register !== 'function' ) {
		return;
	}

	var config = window.perflocaleAbilities || null;

	if ( ! config || ! Array.isArray( config.abilities ) ) {
		return;
	}

	var apiFetch = window.wp.apiFetch;

	if ( typeof apiFetch !== 'function' ) {
		return;
	}

	/**
	 * Invoke a single ability via core's Abilities run-controller.
	 *
	 * WP 7.x ships the run route at
	 * `/wp-abilities/v1/abilities/{namespace}/{slug}/run`. The controller
	 * chooses the HTTP method from the ability's annotations — readonly
	 * runs as GET, destructive-and-idempotent as DELETE, everything else
	 * as POST — and reads the payload from a single wrapped `input` key
	 * (JSON body for POST/DELETE, `?input=<json>` for GET). We mirror that
	 * contract using the annotations localized alongside each ability.
	 *
	 * @param {object} ability Config entry ({ name, readonly, destructive, idempotent }).
	 * @param {object} input   Ability input payload.
	 * @returns {Promise<object>} Resolves with the ability's output payload.
	 */
	function runAbility( ability, input ) {
		var encoded = ability.name.split( '/' ).map( encodeURIComponent ).join( '/' );
		var path    = '/wp-abilities/v1/abilities/' + encoded + '/run';
		var payload = input || {};

		var method = 'POST';

		if ( ability.readonly ) {
			method = 'GET';
		} else if ( ability.destructive && ability.idempotent ) {
			method = 'DELETE';
		}

		if ( method === 'GET' ) {
			return apiFetch( {
				path:   path + '?input=' + encodeURIComponent( JSON.stringify( payload ) ),
				method: 'GET'
			} );
		}

		return apiFetch( {
			path:   path,
			method: method,
			data:   { input: payload }
		} );
	}

	config.abilities.forEach( function ( ability ) {
		if ( ! ability || typeof ability.name !== 'string' ) {
			return;
		}

		try {
			abilitiesApi.register( ability.name, {
				label:        ability.label        || ability.name,
				description:  ability.description  || '',
				inputSchema:  ability.inputSchema  || null,
				outputSchema: ability.outputSchema || null,
				invoke:       function ( input ) {
					return runAbility( ability, input );
				}
			} );
		} catch ( e ) {
			// Registration failed (duplicate name, schema validation, etc.).
			// Swallow per-ability so one bad entry doesn't take down the
			// whole shim.
			if ( window.console && typeof window.console.warn === 'function' ) {
				window.console.warn( '[perflocale] failed to register ability ' + ability.name + ': ' + e.message );
			}
		}
	} );
} )();
