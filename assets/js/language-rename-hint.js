/**
 * PerfLocale - Add Language form: while the slug field holds a
 * region-qualified slug (`ar-ma`), show the rename checkbox whose
 * data-perflocale-rename-prefix is its language part (`ar`). Every other
 * candidate stays hidden and unticked, so a hidden box is never submitted.
 */
( function () {
	'use strict';

	var slug = document.getElementById( 'perflocale-slug' );
	var boxes = document.querySelectorAll( '[data-perflocale-rename-prefix]' );

	if ( ! slug || ! boxes.length ) {
		return;
	}

	function update() {
		var v = ( slug.value || '' ).toLowerCase();
		var dash = v.indexOf( '-' );
		var prefix = dash > 0 ? v.slice( 0, dash ) : '';

		boxes.forEach( function ( b ) {
			b.style.display = prefix && b.getAttribute( 'data-perflocale-rename-prefix' ) === prefix ? 'block' : 'none';

			if ( b.style.display === 'none' ) {
				var cb = b.querySelector( 'input[type="checkbox"]' );
				if ( cb ) {
					cb.checked = false;
				}
			}
		} );
	}

	slug.addEventListener( 'input', update );
	slug.addEventListener( 'change', update );
	update();
}() );
