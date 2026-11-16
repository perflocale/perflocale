/**
 * Settings screen tab strip: one row, with the tabs that do not fit in a "More" menu.
 *
 * The active tab never moves into the menu. Widths are measured once, while every tab sits in the row, so a resize
 * only moves tabs and the row does not jump. Without this script the tabs wrap (admin-core.css); below 782px the
 * strip is hidden and the section select stands in for it.
 *
 * Also opens the Content ReSync subtab for an old link to #perflocale-content-resync.
 */
( function () {
	'use strict';

	/**
	 * Which tabs stay in the row.
	 *
	 * @param {number[]} widths Tab widths, in order.
	 * @param {number}   avail  Room for the tabs.
	 * @param {number}   moreW  Width of the "More" button.
	 * @param {number}   active Index of the active tab, -1 for none.
	 * @return {boolean[]} True for a tab that stays in the row.
	 */
	function split( widths, avail, moreW, active ) {
		var total = 0;
		var keep  = [];
		var i;

		for ( i = 0; i < widths.length; i++ ) {
			total  += widths[ i ];
			keep[ i ] = true;
		}

		if ( total <= avail ) {
			return keep;
		}

		var budget = avail - moreW - ( active >= 0 ? widths[ active ] : 0 );
		var full   = false;

		for ( i = 0; i < widths.length; i++ ) {
			if ( i === active ) {
				continue;
			}

			// Once one tab does not fit, the later ones go too, so the menu keeps the tab order.
			if ( ! full && widths[ i ] <= budget ) {
				budget -= widths[ i ];
			} else {
				full      = true;
				keep[ i ] = false;
			}
		}

		return keep;
	}

	/**
	 * The menu item a key moves to.
	 *
	 * @param {string} key   KeyboardEvent.key.
	 * @param {number} index Current item, -1 for none.
	 * @param {number} count Number of items.
	 * @return {number} Index, or -1 for a key the menu does not handle.
	 */
	function step( key, index, count ) {
		if ( count < 1 ) {
			return -1;
		}

		switch ( key ) {
			case 'ArrowDown':
				return ( index + 1 ) % count;
			case 'ArrowUp':
				return index <= 0 ? count - 1 : index - 1;
			case 'Home':
				return 0;
			case 'End':
				return count - 1;
		}

		return -1;
	}

	window.perflocaleSettingsTabs = { split: split, step: step };

	if ( typeof document === 'undefined' || ! document.querySelector ) {
		return;
	}

	// An old link to the Content ReSync heading: open its subtab.
	if ( window.location && window.location.hash ) {
		var target = document.getElementById( window.location.hash.slice( 1 ) );

		if ( target && target.classList && target.classList.contains( 'perflocale-subtabs__item' ) && ! target.classList.contains( 'is-active' ) ) {
			window.location.replace( target.href );
			return;
		}
	}

	var nav   = document.querySelector( '.perflocale-settings-tabs' );
	var row   = nav ? nav.querySelector( '.perflocale-settings-tabs__items' ) : null;
	var label = nav ? nav.getAttribute( 'data-more-label' ) : '';

	if ( ! row || ! label ) {
		return;
	}

	var help   = nav.querySelector( '.perflocale-help-tab' );
	var tabs   = Array.prototype.slice.call( row.querySelectorAll( 'a.nav-tab' ) );
	var active = -1;

	tabs.forEach( function ( tab, i ) {
		if ( tab.classList.contains( 'nav-tab-active' ) ) {
			active = i;
		}
	} );

	var wrap   = document.createElement( 'div' );
	var button = document.createElement( 'button' );
	var arrow  = document.createElement( 'span' );
	var menu   = document.createElement( 'div' );

	wrap.className   = 'perflocale-settings-tabs__more';
	button.type      = 'button';
	button.className = 'nav-tab perflocale-settings-tabs__more-button';
	button.id        = 'perflocale-settings-tabs-more';
	button.setAttribute( 'aria-haspopup', 'true' );
	button.setAttribute( 'aria-expanded', 'false' );
	button.setAttribute( 'aria-controls', 'perflocale-settings-tabs-menu' );
	button.appendChild( document.createTextNode( label ) );
	arrow.className = 'dashicons dashicons-arrow-down-alt2';
	arrow.setAttribute( 'aria-hidden', 'true' );
	button.appendChild( arrow );
	menu.className = 'perflocale-settings-tabs__menu';
	menu.id        = 'perflocale-settings-tabs-menu';
	menu.setAttribute( 'role', 'menu' );
	menu.setAttribute( 'aria-labelledby', button.id );
	menu.hidden = true;
	wrap.appendChild( button );
	wrap.appendChild( menu );
	row.appendChild( wrap );

	// The one-row CSS keys on this class, not on .js: if this script never runs, the tabs wrap and stay reachable.
	nav.classList.add( 'has-more-menu' );

	var widths = null;
	var moreW  = 0;
	var shown  = null;

	function measure() {
		tabs.forEach( function ( tab ) {
			row.insertBefore( tab, wrap );
		} );
		wrap.hidden = false;
		widths      = tabs.map( function ( tab ) {
			return Math.ceil( tab.getBoundingClientRect().width );
		} );
		moreW       = Math.ceil( button.getBoundingClientRect().width );
		shown       = null;
	}

	function close( focusButton ) {
		menu.hidden = true;
		button.setAttribute( 'aria-expanded', 'false' );

		if ( focusButton ) {
			button.focus();
		}
	}

	function open( focusIndex ) {
		var items = menu.querySelectorAll( 'a' );

		menu.hidden = false;
		button.setAttribute( 'aria-expanded', 'true' );

		if ( focusIndex !== null && items.length ) {
			items[ focusIndex < 0 ? items.length - 1 : focusIndex ].focus();
		}
	}

	function layout() {
		// Hidden below 782px: nothing to fit.
		if ( ! nav.offsetWidth ) {
			return;
		}

		if ( ! widths || widths.indexOf( 0 ) !== -1 ) {
			measure();
		}

		var avail = nav.clientWidth - ( help ? Math.ceil( help.getBoundingClientRect().width ) : 0 ) - 1;
		var keep  = split( widths, avail, moreW, active );
		var key   = keep.join( ',' );

		if ( key === shown ) {
			return;
		}

		shown = key;
		close( false );

		tabs.forEach( function ( tab, i ) {
			if ( keep[ i ] ) {
				tab.removeAttribute( 'role' );
				tab.removeAttribute( 'tabindex' );
				row.insertBefore( tab, wrap );
			} else {
				tab.setAttribute( 'role', 'menuitem' );
				tab.setAttribute( 'tabindex', '-1' );
				menu.appendChild( tab );
			}
		} );

		wrap.hidden = keep.indexOf( false ) === -1;
		nav.classList.add( 'is-ready' );
	}

	button.addEventListener( 'click', function ( e ) {
		if ( menu.hidden ) {
			// A keyboard press (Enter or Space) has no pointer detail: focus the first item.
			open( e.detail === 0 ? 0 : null );
		} else {
			close( false );
		}
	} );

	button.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
			e.preventDefault();
			open( e.key === 'ArrowDown' ? 0 : -1 );
		} else if ( e.key === 'Escape' && ! menu.hidden ) {
			close( true );
		}
	} );

	menu.addEventListener( 'keydown', function ( e ) {
		var items = Array.prototype.slice.call( menu.querySelectorAll( 'a' ) );

		if ( e.key === 'Escape' ) {
			e.preventDefault();
			close( true );
			return;
		}

		if ( e.key === 'Tab' ) {
			close( false );
			return;
		}

		var next = step( e.key, items.indexOf( document.activeElement ), items.length );

		if ( next !== -1 ) {
			e.preventDefault();
			items[ next ].focus();
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		if ( ! menu.hidden && ! wrap.contains( e.target ) ) {
			close( false );
		}
	} );

	var queued = false;

	function queue() {
		if ( queued ) {
			return;
		}

		queued = true;
		window.requestAnimationFrame( function () {
			queued = false;
			layout();
		} );
	}

	if ( window.ResizeObserver ) {
		new window.ResizeObserver( queue ).observe( nav );
	} else {
		window.addEventListener( 'resize', queue );
	}

	layout();
}() );
