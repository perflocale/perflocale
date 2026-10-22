/**
 * PerfLocale - Quick Edit on the taxonomy term list screens: when a term
 * row opens, its language <select> shows the term's language, and every
 * language a linked translation already uses is disabled and marked
 * "(in use)". Reads the row's .perflocale-lang-cell data attributes.
 *
 * core's inline-edit-tax.js can load after this file, so the hook polls
 * for inlineEditTax for up to about 3 seconds.
 *
 * Data: window.perflocaleTermQeI18n (wp_localize_script).
 */
( function ( $ ) {
	'use strict';

	function install() {
		if ( typeof inlineEditTax === 'undefined' ) {
			return false;
		}
		if ( inlineEditTax.__perflocaleHooked ) {
			return true;
		}
		inlineEditTax.__perflocaleHooked = true;

		var origEdit = inlineEditTax.edit;

		inlineEditTax.edit = function ( id ) {
			origEdit.apply( this, arguments );
			if ( typeof id === 'object' ) {
				id = this.getId( id );
			}
			if ( ! id ) {
				return;
			}
			// Term rows are `tag-{id}` regardless of taxonomy.
			var row = document.getElementById( 'tag-' + id );
			var langCell = row ? row.querySelector( '.perflocale-lang-cell' ) : null;
			if ( ! langCell ) {
				return;
			}
			var lang = langCell.getAttribute( 'data-perflocale-lang' ) || '';
			var taken = [];
			try {
				taken = JSON.parse( langCell.getAttribute( 'data-perflocale-taken' ) || '[]' );
			} catch ( e ) {}
			var editRow = document.getElementById( 'edit-' + id );
			var select = editRow ? editRow.querySelector( 'select[name="perflocale_term_language_qe"]' ) : null;
			if ( ! select ) {
				return;
			}
			var i;
			for ( i = 0; i < select.options.length; i++ ) {
				select.options[ i ].disabled = false;
				select.options[ i ].textContent = select.options[ i ].textContent.replace( / \(.*\)$/, '' );
			}
			for ( i = 0; i < select.options.length; i++ ) {
				var val = select.options[ i ].value;
				if ( val === '' || val === lang ) {
					continue;
				}
				if ( taken.indexOf( val ) !== -1 ) {
					select.options[ i ].disabled = true;
					select.options[ i ].textContent += ' (' + window.perflocaleTermQeI18n.inUse + ')';
				}
			}
			select.value = lang || '';
		};
		return true;
	}

	if ( install() ) {
		return;
	}

	var tries = 0;
	var iv = setInterval( function () {
		if ( install() || ++tries > 60 ) {
			clearInterval( iv );
		}
	}, 50 );
}( jQuery ) );
