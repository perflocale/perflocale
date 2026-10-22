/**
 * PerfLocale - language selector + linked-menus block injected into
 * the Appearance > Menus settings sidebar.
 *
 * The legend label, options HTML, linked-menus HTML, and description are
 * the JSON in the data-perflocale-menu-lang attribute of
 * #perflocale-menu-lang-data (MenuManager::print_menu_language_data()).
 * Pre-rendered HTML is required because the per-language <option> labels
 * and the per-language <select> blocks come from server-side data.
 */
( function () {
	function readData() {
		var el = document.getElementById( 'perflocale-menu-lang-data' );
		if ( ! el ) { return null; }
		try {
			return JSON.parse( el.getAttribute( 'data-perflocale-menu-lang' ) || 'null' );
		} catch ( e ) {
			return null;
		}
	}

	function inject() {
		var data = readData();
		if ( ! data ) { return; }
		if ( document.querySelector( '.perflocale-menu-lang-settings' ) ) { return; }

		var wrapper = document.createElement( 'fieldset' );
		wrapper.className = 'menu-settings-group perflocale-menu-lang-settings';

		var legend = document.createElement( 'legend' );
		legend.className = 'menu-settings-group-name';
		legend.textContent = data.legendLabel || '';
		wrapper.appendChild( legend );

		var langWrap = document.createElement( 'p' );
		langWrap.className = 'menu-settings-input-container perflocale-menu-lang-row';
		var label = document.createElement( 'label' );
		label.htmlFor = 'perflocale-menu-language-select';
		label.className = 'screen-reader-text';
		label.textContent = data.legendLabel || '';
		langWrap.appendChild( label );
		var select = document.createElement( 'select' );
		select.name = 'perflocale_menu_language';
		select.id = 'perflocale-menu-language-select';
		select.className = 'perflocale-menu-lang-select';
		select.innerHTML = data.langOptions || '';
		langWrap.appendChild( select );
		wrapper.appendChild( langWrap );

		if ( data.linkedHtml ) {
			var linkedHost = document.createElement( 'div' );
			linkedHost.innerHTML = data.linkedHtml;
			while ( linkedHost.firstChild ) {
				wrapper.appendChild( linkedHost.firstChild );
			}
		}

		var desc = document.createElement( 'p' );
		desc.className = 'description perflocale-menu-lang-desc';
		desc.textContent = data.description || '';
		wrapper.appendChild( desc );

		var settings = document.querySelector( '.menu-settings' );
		if ( settings ) { settings.appendChild( wrapper ); return; }

		var hosts = [
			'.nav-menu-settings',
			'.menu-theme-locations',
			'.auto-add-pages',
			'#nav-menu-meta',
			'#nav-menu-header',
			'.menu-edit',
			'#update-nav-menu',
			'#post-body-content',
		];

		var target = null;
		for ( var i = 0; i < hosts.length; i++ ) {
			var el = document.querySelector( hosts[ i ] );
			if ( el ) { target = el; break; }
		}

		( target || document.body ).appendChild( wrapper );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', inject );
	} else {
		inject();
	}
}() );
