/**
 * Drag-and-drop reorder for the Languages admin list.
 *
 * Vanilla JS, no dependencies. Uses native HTML5 drag-and-drop API with
 * the row-level handle as the only `draggable` element — clicking the row
 * itself (e.g. the language title link) keeps working. On drop:
 *   1. Optimistically reflow the DOM to show the new order immediately.
 *   2. POST the ordered ID array to the REST `/languages/reorder` endpoint.
 *   3. On success, show a brief "Order saved." status.
 *   4. On error, revert the DOM to its pre-drop snapshot and surface the
 *      failure inline (no toast spam, no stale state).
 *
 * Loaded only on the Languages list (not the add/edit form), via the
 * Assets enqueue gate. Destructive row-action confirms live in
 * admin-actions.js (delegated via data-perflocale-confirm).
 */
( function () {
	'use strict';

	function init() {
		var list = document.querySelector( '[data-perflocale-reorderable]' );

		if ( ! list ) {
			return;
		}

		var status   = document.querySelector( '[data-perflocale-reorder-status]' );
		var offset   = parseInt( list.getAttribute( 'data-offset' ) || '0', 10 ) || 0;
		var settings = window.perflocaleAdmin || {};
		var i18n     = settings.i18n || {};

		// The REST root always arrives from rest_url() via wp_localize_script.
		// Never assume /wp-json/ — plain permalinks serve ?rest_route= and the
		// prefix is filterable. Without the payload, leave the list static.
		if ( ! settings.restUrl ) {
			return;
		}

		var endpoint = settings.restUrl + 'languages/reorder';
		var nonce    = settings.restNonce || '';

		var dragging = null;
		var snapshot = null; // pre-drop ID order for revert-on-error.
		var saving = false;   // in-flight guard: one reorder POST at a time.
		var statusTimer = null;

		function setStatus( text, cls ) {
			if ( ! status ) return;
			status.textContent = text || '';
			status.className = 'perflocale-lang-list__status' + ( cls ? ' ' + cls : '' );

			if ( statusTimer ) {
				clearTimeout( statusTimer );
				statusTimer = null;
			}

			if ( cls === 'is-saved' ) {
				statusTimer = setTimeout( function () {
					status.textContent = '';
					status.className = 'perflocale-lang-list__status';
				}, 2400 );
			}
		}

		function currentOrder() {
			return Array.prototype.map.call(
				list.querySelectorAll( '.perflocale-lang-item' ),
				function ( el ) { return parseInt( el.getAttribute( 'data-language-id' ), 10 ); }
			).filter( function ( n ) { return n > 0; } );
		}

		function clearDropMarkers() {
			Array.prototype.forEach.call(
				list.querySelectorAll( '.is-drop-before, .is-drop-after' ),
				function ( el ) {
					el.classList.remove( 'is-drop-before', 'is-drop-after' );
				}
			);
		}

		// Wire each row.
		Array.prototype.forEach.call(
			list.querySelectorAll( '.perflocale-lang-item' ),
			function ( row ) {
				var handle = row.querySelector( '.perflocale-lang-item__handle' );
				if ( ! handle ) return;

				// Only the handle initiates a drag. We set draggable on the
				// row itself (the drag image then captures the whole row),
				// but use mousedown on the handle as the gate so clicking
				// elsewhere on the row doesn't start a drag.
				row.draggable = false;

				handle.addEventListener( 'mousedown', function () {
					row.draggable = true;
				} );

				handle.addEventListener( 'mouseup', function () {
					row.draggable = false;
				} );

				// Keyboard operability: the handle is a focusable button but
				// HTML5 drag-and-drop has no keyboard path — without this the
				// reorder feature simply doesn't exist for keyboard users.
				// Arrow up/down moves the row one slot and persists; the
				// aria-live status region announces the result.
				handle.addEventListener( 'keydown', function ( ev ) {
					if ( ev.key !== 'ArrowUp' && ev.key !== 'ArrowDown' ) {
						return;
					}
					ev.preventDefault();

					if ( saving ) {
						return; // Same serialization as drag: wait for the save.
					}

					var target = ev.key === 'ArrowUp' ? row.previousElementSibling : row.nextElementSibling;

					if ( ! target || ! target.classList.contains( 'perflocale-lang-item' ) ) {
						return; // Already first/last.
					}

					snapshot = currentOrder(); // pre-move baseline for revert-on-error.

					if ( ev.key === 'ArrowUp' ) {
						list.insertBefore( row, target );
					} else if ( target.nextSibling ) {
						list.insertBefore( row, target.nextSibling );
					} else {
						list.appendChild( row );
					}

					handle.focus(); // moving the node drops focus in some browsers.
					persist();
				} );

				row.addEventListener( 'dragstart', function ( ev ) {
					// A reorder POST is still in flight: a second drag would
					// reflow the DOM without ever persisting (persist() is
					// serialized), then "Order saved." would lie about the
					// order on screen. Block the gesture at its source.
					if ( saving || ! row.draggable ) {
						ev.preventDefault();
						return;
					}
					dragging = row;
					snapshot = currentOrder();
					row.classList.add( 'is-dragging' );
					ev.dataTransfer.effectAllowed = 'move';
					// Firefox needs setData to start a drag.
					try { ev.dataTransfer.setData( 'text/plain', row.getAttribute( 'data-language-id' ) ); } catch ( _ ) {}
				} );

				row.addEventListener( 'dragend', function () {
					row.draggable = false;
					row.classList.remove( 'is-dragging' );
					clearDropMarkers();
					dragging = null;
				} );

				row.addEventListener( 'dragover', function ( ev ) {
					if ( ! dragging || dragging === row ) return;
					ev.preventDefault();
					ev.dataTransfer.dropEffect = 'move';

					var rect = row.getBoundingClientRect();
					var midpoint = rect.top + rect.height / 2;
					clearDropMarkers();
					row.classList.add( ev.clientY < midpoint ? 'is-drop-before' : 'is-drop-after' );
				} );

				row.addEventListener( 'dragleave', function () {
					row.classList.remove( 'is-drop-before', 'is-drop-after' );
				} );

				row.addEventListener( 'drop', function ( ev ) {
					if ( ! dragging || dragging === row ) return;
					ev.preventDefault();

					// Same in-flight guard as dragstart — never mutate the
					// DOM into an order that won't be persisted.
					if ( saving ) {
						clearDropMarkers();
						return;
					}

					var rect = row.getBoundingClientRect();
					var dropBefore = ev.clientY < ( rect.top + rect.height / 2 );

					if ( dropBefore ) {
						list.insertBefore( dragging, row );
					} else if ( row.nextSibling ) {
						list.insertBefore( dragging, row.nextSibling );
					} else {
						list.appendChild( dragging );
					}

					clearDropMarkers();
					persist();
				} );
			}
		);

		function persist() {
			var order = currentOrder();

			// No-op: order unchanged.
			if ( snapshot && order.length === snapshot.length && order.every( function ( id, i ) { return id === snapshot[ i ]; } ) ) {
				return;
			}

			// In-flight guard: a second drop landing while the first POST is
			// still open could commit out of order (last-write-wins on the
			// server may persist the OLDER order) and, on failure, revert the
			// DOM to a mid-sequence snapshot. Serialize by ignoring drops until
			// the current save resolves.
			if ( saving ) {
				return;
			}
			saving = true;

			setStatus( i18n.savingOrder || 'Saving order…', 'is-saving' );

			fetch( endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce
				},
				body: JSON.stringify( { order: order, offset: offset } )
			} )
				.then( function ( res ) {
					if ( ! res.ok ) {
						return res.json().then( function ( body ) {
							throw new Error( ( body && body.message ) || ( i18n.reorderFailedStatus || 'Reorder failed (%1$s).' ).replace( '%1$s', String( res.status ) ) );
						} );
					}
					return res.json();
				} )
				.then( function () {
					setStatus( i18n.orderSaved || 'Order saved.', 'is-saved' );
					snapshot = order; // commit new baseline.
				} )
				.catch( function ( err ) {
					setStatus( ( err && err.message ) || i18n.reorderFailed || 'Reorder failed.', 'is-error' );
					// Revert DOM to the pre-drop order.
					if ( snapshot ) {
						snapshot.forEach( function ( id ) {
							var el = list.querySelector( '.perflocale-lang-item[data-language-id="' + id + '"]' );
							if ( el ) list.appendChild( el );
						} );
					}
				} )
				.finally( function () {
					saving = false;
				} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
