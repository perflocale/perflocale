/**
 * PerfLocale - Quick Edit on the post list screens: when a post row opens,
 * its language <select> shows the post's language, and every language a
 * linked translation already uses is disabled and marked "(in use)". Reads
 * the row's .perflocale-lang-cell data attributes.
 *
 * Data: window.perflocaleQeI18n (wp_localize_script).
 */
( function ( $ ) {
	'use strict';

	if ( typeof inlineEditPost === 'undefined' ) {
		return;
	}

	var origEdit = inlineEditPost.edit;

	inlineEditPost.edit = function ( id ) {
		origEdit.apply( this, arguments );
		if ( typeof id === 'object' ) {
			id = this.getId( id );
		}
		if ( ! id ) {
			return;
		}
		var row = document.getElementById( 'post-' + id );
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
		var select = editRow ? editRow.querySelector( 'select[name="perflocale_language_qe"]' ) : null;
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
				select.options[ i ].textContent += ' (' + window.perflocaleQeI18n.inUse + ')';
			}
		}
		select.value = lang || '';
	};
}( jQuery ) );
