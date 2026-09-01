/**
 * PerfLocale — admin Settings page progressive enhancements.
 *
 * Switcher tab: rows tagged with `data-perflocale-show-when-display="<value>"`
 * are shown only when the Display Mode select matches `<value>`. No-ops on
 * tabs where the trigger select isn't present.
 *
 * Clipboard / confirm / submit-busy handlers for data-perflocale-* attrs
 * live in admin-actions.js (delegated, loaded on every plugin admin page).
 */
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

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', syncDisplayRows );
	} else {
		syncDisplayRows();
	}
} )();
