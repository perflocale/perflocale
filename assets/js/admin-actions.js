/**
 * Shared admin progressive enhancement helpers, delegated at document
 * level so they cover elements added after page load.
 *
 * Triggered via data attributes (no inline JS in PHP — keeps the plugin
 * compliant with the no-inline-scripts repo rule):
 *
 *   data-perflocale-confirm="message"
 *     Intercepts clicks (anchors / buttons) and calls window.confirm()
 *     with the attribute value; preventDefault if the user cancels.
 *     Empty attribute value is a no-op (skips the prompt).
 *
 *   data-perflocale-copy="text"
 *     Copies the attribute value to the clipboard on click / Enter / Space.
 *     Flashes a brief background color to confirm the copy. Silent
 *     fallback when navigator.clipboard is unavailable. Pair with
 *     role="button" + tabindex="0" on non-button host elements.
 *
 *   data-perflocale-submit-busy="busy text"
 *     On click of a form submit button, sets textContent to the
 *     attribute value (or "…" if empty), disables the button, and
 *     submits the parent form. Prevents double-submit on slow handlers.
 */
( function () {
	'use strict';

	function bindConfirm() {
		document.addEventListener( 'click', function ( e ) {
			var el = e.target.closest( '[data-perflocale-confirm]' );
			if ( ! el ) {
				return;
			}
			var message = el.getAttribute( 'data-perflocale-confirm' );
			if ( message && ! window.confirm( message ) ) {
				e.preventDefault();
			}
		} );
	}

	function flash( el ) {
		var original = el.style.background;
		el.style.background = '#dcfce7';
		setTimeout( function () {
			el.style.background = original;
		}, 800 );
	}

	function copyTarget( el ) {
		var value = el.getAttribute( 'data-perflocale-copy' );
		if ( ! value || ! navigator.clipboard || ! navigator.clipboard.writeText ) {
			return;
		}
		navigator.clipboard.writeText( value ).then( function () {
			flash( el );
		} ).catch( function () {
			// Silent — user can still select+copy manually.
		} );
	}

	function bindClipboard() {
		document.addEventListener( 'click', function ( e ) {
			var el = e.target.closest( '[data-perflocale-copy]' );
			if ( el ) {
				copyTarget( el );
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Enter' && e.key !== ' ' ) {
				return;
			}
			var el = e.target.closest( '[data-perflocale-copy]' );
			if ( el ) {
				e.preventDefault();
				copyTarget( el );
			}
		} );
	}

	// Keeps each busy button's original inner markup (e.g. a dashicon <span>)
	// so a restore can bring it back instead of leaving the bare busy text.
	var submitBusyOriginal = new WeakMap();

	function restoreSubmitBusy( el ) {
		if ( submitBusyOriginal.has( el ) ) {
			el.innerHTML = submitBusyOriginal.get( el );
			submitBusyOriginal.delete( el );
		}
		el.disabled = false;
	}

	function bindSubmitBusy() {
		// A form.submit() whose navigation never completes would otherwise
		// strand the button disabled with its label swapped and its icon gone.
		// pageshow fires when the browser restores the frozen page from
		// bfcache (Back navigation) — the common way such a button reappears.
		window.addEventListener( 'pageshow', function () {
			var busy = document.querySelectorAll( '[data-perflocale-submit-busy]' );
			for ( var i = 0; i < busy.length; i++ ) {
				restoreSubmitBusy( busy[ i ] );
			}
		} );

		document.addEventListener( 'click', function ( e ) {
			var el = e.target.closest( '[data-perflocale-submit-busy]' );
			if ( ! el || ! el.form || el.disabled ) {
				return;
			}
			if ( ! submitBusyOriginal.has( el ) ) {
				submitBusyOriginal.set( el, el.innerHTML );
			}
			el.textContent = el.getAttribute( 'data-perflocale-submit-busy' ) || '…';
			el.disabled = true;

			// An unsaved-changes guard can cancel the navigation ("Stay"), which
			// fires no pageshow — the button would stay disabled with its icon
			// gone until a manual reload. A cancelled navigation never sent the
			// request, so re-enabling is safe there; pagehide cancels the timer
			// whenever the page really is leaving, so a committed navigation
			// keeps the busy state until it unloads.
			var revive = window.setTimeout( function () {
				restoreSubmitBusy( el );
			}, 3000 );

			window.addEventListener(
				'pagehide',
				function () {
					window.clearTimeout( revive );
				},
				{ once: true }
			);

			el.form.submit();
		} );
	}

	function init() {
		bindConfirm();
		bindClipboard();
		bindSubmitBusy();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
