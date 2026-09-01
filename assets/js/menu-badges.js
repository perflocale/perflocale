/**
 * PerfLocale - language badges on the Appearance > Menus screen.
 *
 * Reads the post-id -> language-slug map from window.perflocaleMenuBadges
 * (seeded by wp_add_inline_script 'before') and decorates checkbox labels
 * with a small uppercase badge showing the language code.
 */
( function () {
	if ( ! window.perflocaleMenuBadges || ! window.perflocaleMenuBadges.langMap ) { return; }
	var langMap = window.perflocaleMenuBadges.langMap;

	function addBadges() {
		var items = document.querySelectorAll(
			'#menu-settings-column .categorychecklist li label, #menu-settings-column .posttype-tabs li label'
		);
		items.forEach( function ( label ) {
			if ( label.dataset.perflocaleLabeled ) { return; }
			label.dataset.perflocaleLabeled = '1';
			var input = label.querySelector( 'input[type="checkbox"]' );
			if ( ! input ) { return; }
			var postId = parseInt( input.value, 10 );
			if ( ! postId || ! langMap[ postId ] ) { return; }
			var badge = document.createElement( 'span' );
			badge.style.cssText = 'display:inline-block;background:#e5e7eb;color:#374151;font-size:10px;font-weight:600;padding:1px 4px;border-radius:3px;margin-left:4px;vertical-align:middle;';
			badge.textContent = langMap[ postId ];
			label.appendChild( badge );
		} );
	}

	function init() {
		addBadges();
		var column = document.getElementById( 'menu-settings-column' );
		if ( column ) {
			var observer = new MutationObserver( function () { setTimeout( addBadges, 50 ); } );
			observer.observe( column, { childList: true, subtree: true } );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
