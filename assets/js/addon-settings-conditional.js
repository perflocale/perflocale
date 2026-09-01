/**
 * PerfLocale — auto-addon settings form: conditional field visibility.
 *
 * Each row in the auto-generated addon settings form may carry a
 * `data-perflocale-show-if='{json}'` attribute. The JSON encodes the same
 * spec the PHP `AddonSettings::evaluate_show_if()` evaluator handles:
 *
 *   • Simple equality (implicit AND across keys):
 *       { "wc_currency_per_lang": true }
 *       { "mode": "advanced", "logging": true }
 *
 *   • Nested operator:
 *       { "op": "OR", "rules": [
 *           { "wc_currency_per_lang": true },
 *           { "op": "AND", "rules": [
 *               { "mode": "pro" },
 *               { "beta": true }
 *           ]}
 *       ]}
 *
 * PHP sets the initial display state at render time; this script keeps it
 * in sync as the user toggles driver fields. No FOUC — the initial render
 * is already correct from the server.
 */
( function () {
	'use strict';

	// Loose equality (mirrors PHP's `!=`) so '1' matches `true`, '0' matches
	// `false`, "" matches null/undefined — the conventions checkbox-style
	// fields produce in the DOM differ from the canonical booleans stored
	// server-side, and we want the same answer in both places.
	function looseEq( a, b ) {
		if ( a === b ) { return true; }
		if ( a == null && b == null ) { return true; }
		if ( typeof a === 'boolean' || typeof b === 'boolean' ) {
			// '1'/'on'/'true' → true; anything else → false (for the non-bool side).
			var toBool = function ( v ) {
				if ( typeof v === 'boolean' ) { return v; }
				if ( v == null || v === '' || v === '0' || v === 'false' ) { return false; }
				return true;
			};
			return toBool( a ) === toBool( b );
		}
		// String compare for everything else — Number → "1" === "1".
		return String( a ) === String( b );
	}

	function evaluate( spec, values ) {
		if ( ! spec || typeof spec !== 'object' ) { return true; }

		// Nested operator form
		if ( spec.op && Array.isArray( spec.rules ) ) {
			var op = String( spec.op ).toUpperCase();
			if ( op === 'OR' ) {
				return spec.rules.some( function ( r ) { return evaluate( r, values ); } );
			}
			// Default AND
			return spec.rules.every( function ( r ) { return evaluate( r, values ); } );
		}

		// Simple form — every key must equal its expected value.
		for ( var key in spec ) {
			if ( ! Object.prototype.hasOwnProperty.call( spec, key ) ) { continue; }
			if ( ! looseEq( values[ key ], spec[ key ] ) ) {
				return false;
			}
		}
		return true;
	}

	function readValues( container ) {
		var values = {};
		// Driver inputs may be tagged either with the canonical
		// `data-perflocale-field-name` attribute (new auto-addon forms)
		// OR carry their identity via the standard HTML `name` attribute
		// (legacy settings markup like the WC tab). Read both — the
		// canonical attribute wins when both are present.
		var inputs = container.querySelectorAll( '[data-perflocale-field-name], input[name], select[name], textarea[name]' );

		inputs.forEach( function ( input ) {
			var key = input.getAttribute( 'data-perflocale-field-name' );
			if ( ! key ) {
				key = input.getAttribute( 'name' );
			}
			if ( ! key ) { return; }

			if ( input.type === 'checkbox' ) {
				values[ key ] = input.checked;
			} else if ( input.type === 'radio' ) {
				if ( input.checked ) {
					values[ key ] = input.value;
				}
			} else {
				values[ key ] = input.value;
			}
		} );

		return values;
	}

	function applyVisibility( container ) {
		var values = readValues( container );
		var rows   = container.querySelectorAll( '[data-perflocale-show-if]' );

		rows.forEach( function ( row ) {
			var raw  = row.getAttribute( 'data-perflocale-show-if' );
			var spec;
			try {
				spec = JSON.parse( raw );
			} catch ( e ) {
				return; // Malformed JSON — leave row alone.
			}

			row.style.display = evaluate( spec, values ) ? '' : 'none';
		} );
	}

	// Collect every container that holds at least one show_if row. We
	// look at FORM ancestors first (auto-addon settings forms, the WC
	// settings tab's outer form, anything else that follows the same
	// pattern); for show_if rows outside of any form (rare — but possible
	// on custom admin screens) we fall back to a synthetic "document"
	// container that watches all bubbling change/input events from the
	// page root.
	function findContainers() {
		var rows = document.querySelectorAll( '[data-perflocale-show-if]' );
		if ( rows.length === 0 ) { return []; }

		var containers = [];
		var seen       = [];

		rows.forEach( function ( row ) {
			// Nearest <form> if any — same form contains the driver inputs
			// 99% of the time, so scoping reduces unnecessary recalcs.
			var form = row.closest( 'form' );
			var container = form || document;
			if ( seen.indexOf( container ) === -1 ) {
				seen.push( container );
				containers.push( container );
			}
		} );

		return containers;
	}

	function init() {
		var containers = findContainers();

		containers.forEach( function ( container ) {
			// Initial sync (server already did this, but harmless to re-apply
			// after dynamic field injections from plugins / browser extensions).
			applyVisibility( container );

			// Single delegated listener — change events bubble.
			container.addEventListener( 'change', function () {
				applyVisibility( container );
			} );
			// Also re-run on input for text/textarea/number fields so the
			// visibility tracks typing in real time, not just blur.
			container.addEventListener( 'input', function ( ev ) {
				if ( ev.target && ev.target.matches && ev.target.matches( 'input[type="text"], input[type="number"], textarea' ) ) {
					applyVisibility( container );
				}
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
