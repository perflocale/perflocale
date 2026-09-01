/**
 * PerfLocale Language Switcher — dropdown keyboard, click, and event
 * handling.
 *
 * Public events fired on the switcher `<nav>` (all bubble):
 *   - `perflocale:switcher:open`     (after open, not cancelable)
 *   - `perflocale:switcher:close`    (after close, not cancelable)
 *   - `perflocale:switcher:navigate` (before option-click navigation, cancelable)
 */
( function () {
	'use strict';

	var ROOT_SELECTOR    = '.perflocale-switcher-block--dropdown';
	var TRIGGER_SELECTOR = '.perflocale-dd__trigger';
	var PANEL_SELECTOR   = '.perflocale-dd__panel';
	var OPTION_SELECTOR  = '[role=option]:not([aria-disabled="true"])';
	var OPEN_CLASS       = 'perflocale-dd--open';

	var setupRegistry  = new WeakMap();
	var liveReposition = new WeakMap();

	function positionPanel( btn, panel ) {
		panel.style.top    = '';
		panel.style.bottom = '';
		panel.style.insetInlineStart = '';
		panel.style.insetInlineEnd   = '';

		var btnRect       = btn.getBoundingClientRect();
		var panelWidth    = panel.offsetWidth;
		var panelHeight   = panel.offsetHeight;
		var viewportW     = window.innerWidth;
		var viewportH     = window.innerHeight;

		var adminBar       = document.getElementById( 'wpadminbar' );
		var adminBarHeight = adminBar ? adminBar.offsetHeight : 0;

		var panelStyle  = getComputedStyle( panel );
		var rawOffset   = panelStyle.getPropertyValue( '--perflocale-dd-panel-offset' ).trim();
		var panelOffset = rawOffset ? parseFloat( rawOffset ) || 0 : 0;

		var spaceBelow = viewportH - btnRect.bottom - panelOffset;
		var spaceAbove = btnRect.top - adminBarHeight - panelOffset;

		if ( spaceBelow < panelHeight && spaceAbove > spaceBelow ) {
			panel.style.bottom = '100%';
			panel.style.top    = 'auto';
		} else {
			panel.style.top    = '100%';
			panel.style.bottom = 'auto';
		}

		var isRtl = getComputedStyle( panel ).direction === 'rtl';

		var spaceToInlineEnd   = isRtl ? btnRect.right             : viewportW - btnRect.left;
		var spaceToInlineStart = isRtl ? viewportW - btnRect.left  : btnRect.right;

		if ( panelWidth > spaceToInlineEnd && spaceToInlineStart >= panelWidth ) {
			panel.style.insetInlineEnd   = '0';
			panel.style.insetInlineStart = 'auto';
		} else {
			panel.style.insetInlineStart = '0';
			panel.style.insetInlineEnd   = 'auto';
		}
	}

	function startLivePositioning( nav, btn, panel ) {
		var reposition = function () { positionPanel( btn, panel ); };

		window.addEventListener( 'scroll',  reposition, { capture: true, passive: true } );
		window.addEventListener( 'resize',  reposition );

		var ro = null;

		if ( typeof ResizeObserver !== 'undefined' ) {
			ro = new ResizeObserver( reposition );
			ro.observe( btn );
		}

		liveReposition.set( nav, function () {
			window.removeEventListener( 'scroll', reposition, { capture: true } );
			window.removeEventListener( 'resize', reposition );
			if ( ro ) { ro.disconnect(); }
		} );
	}

	function stopLivePositioning( nav ) {
		var teardown = liveReposition.get( nav );

		if ( teardown ) {
			teardown();
			liveReposition.delete( nav );
		}
	}

	function dispatch( nav, name, detail, cancelable ) {
		if ( typeof CustomEvent !== 'function' ) {
			return true;
		}

		var event = new CustomEvent( 'perflocale:switcher:' + name, {
			bubbles:    true,
			cancelable: !! cancelable,
			detail:     detail
		} );

		return nav.dispatchEvent( event );
	}

	function closeAllExcept( exceptNav ) {
		var openPanels = document.querySelectorAll( PANEL_SELECTOR + '.' + OPEN_CLASS );

		for ( var i = 0; i < openPanels.length; i++ ) {
			var panel = openPanels[ i ];
			var nav   = panel.closest( ROOT_SELECTOR );

			if ( nav === exceptNav || ! nav ) {
				continue;
			}

			var btn = nav.querySelector( TRIGGER_SELECTOR );

			if ( ! btn ) {
				continue;
			}

			close( nav, btn, panel, false );
		}
	}

	function open( nav, btn, panel, focusFirstOption ) {
		closeAllExcept( nav );
		panel.classList.add( OPEN_CLASS );
		btn.setAttribute( 'aria-expanded', 'true' );
		positionPanel( btn, panel );
		startLivePositioning( nav, btn, panel );

		dispatch( nav, 'open', { nav: nav, trigger: btn, panel: panel }, false );

		if ( focusFirstOption ) {
			var first = panel.querySelector( OPTION_SELECTOR );
			if ( first ) { first.focus(); }
		}
	}

	function close( nav, btn, panel, returnFocusToTrigger ) {
		panel.classList.remove( OPEN_CLASS );
		btn.setAttribute( 'aria-expanded', 'false' );
		stopLivePositioning( nav );

		dispatch( nav, 'close', { nav: nav, trigger: btn, panel: panel }, false );

		if ( returnFocusToTrigger ) {
			btn.focus();
		}
	}

	function setupTrigger( nav ) {
		if ( setupRegistry.has( nav ) ) {
			return;
		}

		var btn   = nav.querySelector( TRIGGER_SELECTOR );
		var panel = nav.querySelector( PANEL_SELECTOR );

		if ( ! btn || ! panel ) {
			return;
		}

		setupRegistry.set( nav, true );

		panel.addEventListener( 'click', function ( e ) {
			var option = e.target.closest( '.perflocale-dd__option' );

			if ( ! option || option.tagName !== 'A' ) {
				return;
			}

			var allowed = dispatch( nav, 'navigate', {
				nav:     nav,
				option:  option,
				slug:    option.getAttribute( 'hreflang' ) || option.getAttribute( 'lang' ) || '',
				url:     option.getAttribute( 'href' ) || '',
				trigger: btn
			}, true );

			if ( ! allowed ) {
				e.preventDefault();
			}
		} );

		btn.addEventListener( 'click', function ( e ) {
			var isOpen = panel.classList.contains( OPEN_CLASS );

			if ( isOpen ) {
				close( nav, btn, panel, false );
			} else {
				// e.detail === 0 distinguishes keyboard activation
				// (Enter/Space) from a mouse click; we auto-focus the
				// first option only on keyboard.
				open( nav, btn, panel, e.detail === 0 );
			}

			e.preventDefault();
			e.stopPropagation();
		} );

		nav.addEventListener( 'keydown', function ( e ) {
			var t      = e.target;
			var isOpen = panel.classList.contains( OPEN_CLASS );
			var opts   = panel.querySelectorAll( OPTION_SELECTOR );

			if ( t === btn ) {
				if ( e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ' ) {
					if ( ! isOpen ) { open( nav, btn, panel, false ); }
					if ( opts.length ) { opts[ 0 ].focus(); }
					e.preventDefault();
				} else if ( e.key === 'ArrowUp' ) {
					if ( ! isOpen ) { open( nav, btn, panel, false ); }
					if ( opts.length ) { opts[ opts.length - 1 ].focus(); }
					e.preventDefault();
				} else if ( e.key === 'Escape' && isOpen ) {
					close( nav, btn, panel, false );
					e.preventDefault();
				}

				return;
			}

			if ( ! t.closest( PANEL_SELECTOR ) ) {
				return;
			}

			var idx = -1;

			for ( var i = 0; i < opts.length; i++ ) {
				if ( opts[ i ] === t || opts[ i ].contains( t ) ) {
					idx = i;
					break;
				}
			}

			if ( idx === -1 ) {
				return;
			}

			if ( e.key === 'ArrowDown' ) {
				opts[ ( idx + 1 ) % opts.length ].focus();
				e.preventDefault();
			} else if ( e.key === 'ArrowUp' ) {
				opts[ ( idx - 1 + opts.length ) % opts.length ].focus();
				e.preventDefault();
			} else if ( e.key === 'Home' ) {
				opts[ 0 ].focus();
				e.preventDefault();
			} else if ( e.key === 'End' ) {
				opts[ opts.length - 1 ].focus();
				e.preventDefault();
			} else if ( e.key === 'Escape' ) {
				close( nav, btn, panel, true );
				e.preventDefault();
			} else if ( e.key.length === 1 && ! e.ctrlKey && ! e.metaKey && ! e.altKey && /\S/.test( e.key ) ) {
				// First-letter type-ahead, mirrors native <select>. Match the
				// name span, not the whole option: a leading flag emoji is
				// rendered with no separating whitespace, so textContent would
				// start with its surrogate. Any script's letters are accepted.
				var ch = e.key.toLowerCase();
				for ( var j = 1; j <= opts.length; j++ ) {
					var probe  = opts[ ( idx + j ) % opts.length ];
					var nameEl = probe.querySelector( 'span:not(.perflocale-dd__flag)' );
					var label  = ( ( nameEl ? nameEl.textContent : probe.textContent ) || '' ).trim().toLowerCase();
					if ( label.charAt( 0 ) === ch ) {
						probe.focus();
						e.preventDefault();
						break;
					}
				}
			}
		} );

		// The listbox popup must close when focus leaves it (WAI-ARIA APG).
		// The keydown handler can't cover this: options are tabindex="-1", so
		// Tab moves focus straight out without an option keydown we'd see. A
		// known relatedTarget is handled at once; a null one (Safari fires it
		// on option mousedown) is deferred a frame so an option's own click
		// navigation isn't cut short by hiding the panel mid-gesture.
		nav.addEventListener( 'focusout', function ( e ) {
			if ( ! panel.classList.contains( OPEN_CLASS ) ) {
				return;
			}

			var to = e.relatedTarget;

			if ( to ) {
				if ( ! nav.contains( to ) ) {
					close( nav, btn, panel, false );
				}
				return;
			}

			( window.requestAnimationFrame || function ( cb ) { return setTimeout( cb, 0 ); } )( function () {
				if ( panel.classList.contains( OPEN_CLASS ) && ! nav.contains( document.activeElement ) ) {
					close( nav, btn, panel, false );
				}
			} );
		} );
	}

	function setupAllInDocument() {
		var roots = document.querySelectorAll( ROOT_SELECTOR );
		for ( var i = 0; i < roots.length; i++ ) {
			setupTrigger( roots[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', setupAllInDocument );
	} else {
		setupAllInDocument();
	}

	if ( typeof MutationObserver !== 'undefined' ) {
		var observer = new MutationObserver( function ( mutations ) {
			for ( var i = 0; i < mutations.length; i++ ) {
				var added = mutations[ i ].addedNodes;
				for ( var j = 0; j < added.length; j++ ) {
					var node = added[ j ];
					if ( node.nodeType !== 1 ) { continue; }
					if ( node.matches && node.matches( ROOT_SELECTOR ) ) {
						setupTrigger( node );
					}
					if ( node.querySelectorAll ) {
						var inside = node.querySelectorAll( ROOT_SELECTOR );
						for ( var k = 0; k < inside.length; k++ ) {
							setupTrigger( inside[ k ] );
						}
					}
				}
			}
		} );

		observer.observe( document.body, { childList: true, subtree: true } );
	}

	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( ROOT_SELECTOR ) ) {
			return;
		}
		closeAllExcept( null );
	} );

	// Native <select> dropdown used by the switcher-dropdown.php template.
	var selects = document.querySelectorAll( '[data-perflocale-switcher]' );

	for ( var s = 0; s < selects.length; s++ ) {
		if ( selects[ s ].tagName === 'SELECT' ) {
			selects[ s ].addEventListener( 'change', function () {
				var url = this.value;
				if ( url ) {
					window.location.href = url;
				}
			} );
		}
	}
} )();
