/**
 * Bulk-actions UI for the Translations admin page.
 *
 * Behaviours:
 *   1. The primary <select data-perflocale-bulk-select> drives which
 *      secondary input (status / priority / user / language / date) is
 *      visible. Hidden inputs share the name `bulk_value` so only the
 *      visible one submits.
 *   2. Top-of-table dropdown is mirrored by a bottom-of-table copy
 *      (data-perflocale-bulk-select-mirror); changing one updates the
 *      other so the user sees a consistent state.
 *   3. The select-all checkbox in the header toggles every row checkbox
 *      bound to the same form (via the HTML5 `form="..."` attribute).
 *   4. Apply button: validate that an action AND at least one row are
 *      selected. Confirm before destructive bulk delete.
 *
 * Loaded only on the Translations list view via Assets.php.
 */
( function () {
	'use strict';

	function init() {
		var bulkForm = document.querySelector( '[data-perflocale-bulk-form]' );

		if ( ! bulkForm ) {
			return;
		}

		var formId = bulkForm.id;
		var primary = document.querySelector( '[data-perflocale-bulk-select]' );
		var mirror  = document.querySelector( '[data-perflocale-bulk-select-mirror]' );
		var applyButtons = document.querySelectorAll( '[data-perflocale-bulk-apply]' );

		// All secondary value inputs that appear/disappear based on the action.
		var secondaryMap = {
			set_source_language: '[data-perflocale-bulk-value-language]',
			set_target_language: '[data-perflocale-bulk-value-language]',
			set_status:          '[data-perflocale-bulk-value-status]',
			set_priority:        '[data-perflocale-bulk-value-priority]',
			reassign:            '[data-perflocale-bulk-value-user]',
			set_deadline:        '[data-perflocale-bulk-value-deadline]',
			mt_translate:        '[data-perflocale-bulk-value-target-lang]',
			mark_needs_update:   '[data-perflocale-bulk-value-target-lang]',
		};

		var secondaryEls = {};
		Object.keys( secondaryMap ).forEach( function ( action ) {
			var el = document.querySelector( secondaryMap[ action ] );
			if ( el ) {
				secondaryEls[ action ] = el;
			}
		} );

		function showSecondary( action ) {
			// Hide all, then show the matching one. Disable hidden ones so
			// they don't submit empty values that confuse the server.
			Object.keys( secondaryEls ).forEach( function ( key ) {
				var el = secondaryEls[ key ];
				el.hidden = true;
				el.disabled = true;
			} );

			var match = secondaryEls[ action ];
			if ( match ) {
				match.hidden = false;
				match.disabled = false;
			}
		}

		function syncDropdowns( source ) {
			if ( ! primary ) return;
			// Mirror is optional — pages without a bottom bulk-actions bar
			// (e.g. Translations) skip the cross-sync but still need to
			// reveal the right secondary input on every change.
			if ( mirror ) {
				var target = source === primary ? mirror : primary;
				if ( target.value !== source.value ) {
					target.value = source.value;
				}
			}
			showSecondary( primary.value );
		}

		if ( primary ) {
			primary.addEventListener( 'change', function () { syncDropdowns( primary ); } );
		}
		if ( mirror ) {
			mirror.addEventListener( 'change', function () { syncDropdowns( mirror ); } );
		}

		// Initial state.
		showSecondary( primary ? primary.value : '' );

		// Select-all checkbox(es). There may be one in the header AND one in
		// the footer — keep them in sync with each other and with each row
		// checkbox bound to this form.
		var selectAlls = document.querySelectorAll( '[data-perflocale-bulk-select-all]' );
		var rowCheckboxes = function () {
			return document.querySelectorAll( 'input[form="' + formId + '"][data-perflocale-bulk-row]' );
		};

		selectAlls.forEach( function ( sa ) {
			sa.addEventListener( 'change', function () {
				var rows = rowCheckboxes();
				rows.forEach( function ( cb ) { cb.checked = sa.checked; } );
				selectAlls.forEach( function ( other ) { other.checked = sa.checked; } );
			} );
		} );

		// Keep select-all in indeterminate state when only some rows are checked.
		document.addEventListener( 'change', function ( ev ) {
			if ( ! ev.target.matches( 'input[form="' + formId + '"][data-perflocale-bulk-row]' ) ) {
				return;
			}
			var rows = rowCheckboxes();
			var checked = 0;
			rows.forEach( function ( cb ) { if ( cb.checked ) checked++; } );
			selectAlls.forEach( function ( sa ) {
				sa.checked = checked === rows.length && rows.length > 0;
				sa.indeterminate = checked > 0 && checked < rows.length;
			} );
		} );

		// Apply button: client-side validation + delete confirmation.
		applyButtons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( ev ) {
				var action = primary ? primary.value : '';
				if ( ! action ) {
					ev.preventDefault();
					alert( ( window.perflocaleBulk && perflocaleBulk.pickAction ) || 'Pick a bulk action first.' );
					return;
				}

				var rows = rowCheckboxes();
				var anyChecked = false;
				rows.forEach( function ( cb ) { if ( cb.checked ) anyChecked = true; } );
				if ( ! anyChecked ) {
					ev.preventDefault();
					alert( ( window.perflocaleBulk && perflocaleBulk.pickRows ) || 'Pick at least one item first.' );
					return;
				}

				if ( action === 'delete' ) {
					var prompt = btn.getAttribute( 'data-confirm-delete' ) || ( window.perflocaleBulk && perflocaleBulk.confirmDelete );
					if ( prompt && ! window.confirm( prompt ) ) {
						ev.preventDefault();
					}
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
