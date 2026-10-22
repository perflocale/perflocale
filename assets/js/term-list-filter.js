/**
 * PerfLocale - language filter on the taxonomy term list screens
 * (edit-tags.php): a labelled language <select> and a Filter button after
 * the bulk actions. Filter reloads the list with perflocale_lang.
 *
 * Data: window.perflocaleTermFilter (wp_localize_script), with the options
 * as { value, label, selected } so no server HTML reaches innerHTML.
 */
( function () {
	'use strict';

	var data = window.perflocaleTermFilter;
	var tablenav = document.querySelector( '#posts-filter .tablenav.top .bulkactions' );

	if ( ! data || ! tablenav ) {
		return;
	}

	var label = document.createElement( 'label' );
	label.htmlFor = 'perflocale-filter-by-language';
	label.className = 'screen-reader-text';
	label.textContent = data.label;

	var select = document.createElement( 'select' );
	select.name = 'perflocale_lang';
	select.id = 'perflocale-filter-by-language';
	select.style.marginLeft = '6px';

	( data.options || [] ).forEach( function ( item ) {
		var option = document.createElement( 'option' );
		option.value = item.value;
		option.textContent = item.label;
		if ( item.selected ) {
			option.defaultSelected = true;
		}
		select.appendChild( option );
	} );

	var btn = document.createElement( 'input' );
	btn.type = 'button';
	btn.className = 'button';
	btn.value = data.filterBtn;
	btn.style.marginLeft = '4px';
	btn.addEventListener( 'click', function () {
		var val = select.value;
		var url = data.filterUrl;
		if ( val ) {
			url += '&perflocale_lang=' + encodeURIComponent( val );
		}
		window.location.href = url;
	} );

	tablenav.parentNode.insertBefore( label, tablenav.nextSibling );
	label.parentNode.insertBefore( select, label.nextSibling );
	select.parentNode.insertBefore( btn, select.nextSibling );
}() );
