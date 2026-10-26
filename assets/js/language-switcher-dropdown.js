/**
 * PerfLocale Language Switcher - dropdown keyboard, click, and event
 * handling.
 *
 * Events bubble from the switcher `<nav>`, each documented above its
 * dispatch call. A trigger that stops being displayed closes the panel
 * (source 'hidden') and moves no focus.
 *
 * The server renders the language links without a tabindex, so with scripting
 * off (the `scripting: none` block in frontend.css shows them as a plain list)
 * each link is a Tab stop. Set-up takes them out of the Tab order: with the
 * script the list is a listbox driven by the arrow keys.
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
	var setupCount     = 0;

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
		var reposition = function () {
			// No layout box: display:none on the trigger or an ancestor, or
			// the trigger was removed. The ResizeObserver reports exactly this
			// (a 0x0 size); scroll and resize cover browsers without it.
			if ( ! btn.getClientRects().length ) {
				closeHidden( nav, btn, panel );
				return;
			}
			positionPanel( btn, panel );
		};

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

	// An open dropdown whose trigger lost its layout box closes through the
	// normal close path (aria-expanded, open class, handlers, close event).
	// Focus is left where it is: the trigger has no box to take it, and which
	// visible control stands in for this switcher is the theme's knowledge.
	function closeHidden( nav, btn, panel ) {
		if ( panel.classList.contains( OPEN_CLASS ) ) {
			close( nav, btn, panel, false, 'hidden' );
		}
	}

	// Language links stay out of the Tab order while the script manages
	// focus. A tabindex set through the perflocale/switcher/link_attrs filter
	// is kept.
	function removeOptionsFromTabOrder( panel ) {
		var options = panel.querySelectorAll( '[role=option]' );

		for ( var i = 0; i < options.length; i++ ) {
			if ( ! options[ i ].hasAttribute( 'tabindex' ) ) {
				options[ i ].setAttribute( 'tabindex', '-1' );
			}
		}
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

		return nav.dispatchEvent( new CustomEvent( name, {
			bubbles:    true,
			cancelable: !! cancelable,
			detail:     detail
		} ) );
	}

	// A Language read from an option's or the trigger's data-pl-* attributes.
	function readLanguage( el ) {
		return {
			slug:       el.getAttribute( 'data-pl-slug' ) || '',
			locale:     el.getAttribute( 'data-pl-locale' ) || '',
			name:       el.getAttribute( 'data-pl-name' ) || '',
			nativeName: el.getAttribute( 'data-pl-native' ) || '',
			isDefault:  el.getAttribute( 'data-pl-default' ) === '1'
		};
	}

	function readOption( el ) {
		var o = readLanguage( el );

		o.url       = el.tagName === 'A' ? el.getAttribute( 'href' ) || '' : '';
		o.available = el.getAttribute( 'data-pl-missing' ) !== '1';
		o.option    = el;

		return Object.freeze( o );
	}

	/**
	 * @typedef {Object} SwitcherDetail
	 * @property {number} version 1.
	 * @property {HTMLElement} nav The nav root.
	 * @property {HTMLElement} trigger The button.
	 * @property {HTMLElement} panel The options list.
	 * @property {string} instance The nav's id, else 'perflocale-switcher-<n>'.
	 * @property {Language} current The page's language.
	 * @property {Option[]} languages The options in display order.
	 * @property {string} source What caused the event.
	 */
	/**
	 * @typedef {Language} Option
	 * @property {string} url The link; '' without one.
	 * @property {boolean} available The page has a translation there.
	 * @property {HTMLElement} option The option element.
	 */
	/**
	 * current and languages are read on first use, then kept.
	 *
	 * @returns {SwitcherDetail}
	 */
	function detail( nav, source ) {
		var st = setupRegistry.get( nav );

		return {
			version:  1,
			nav:      nav,
			trigger:  st.btn,
			panel:    st.panel,
			instance: st.instance,
			get current() {
				return st.current || ( st.current = Object.freeze( readLanguage( st.btn ) ) );
			},
			get languages() {
				return st.languages || ( st.languages = Object.freeze(
					Array.prototype.map.call( st.panel.querySelectorAll( '[role=option]' ), readOption )
				) );
			},
			source:   source
		};
	}

	function closeAllExcept( exceptNav, source ) {
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

			close( nav, btn, panel, false, source );
		}
	}

	function open( nav, btn, panel, focusFirstOption, source ) {
		/**
		 * @hook perflocale:switcher:beforeopen
		 * @kind event
		 * @since 1.0.7.4
		 * @cardinality transition
		 * @cancelable true
		 * @fires Before the panel opens, before any change. preventDefault() leaves every switcher and the focus as they were.
		 * @context {SwitcherDetail} detail
		 * @property {string} detail.source 'click' (a mouse click or a tap on a touch screen) or 'keyboard' (Enter, Space or an arrow key).
		 * @example document.addEventListener( 'perflocale:switcher:beforeopen', ( e ) => e.preventDefault() );
		 */
		if ( ! dispatch( nav, 'perflocale:switcher:beforeopen', detail( nav, source ), true ) ) {
			return false;
		}

		removeOptionsFromTabOrder( panel );
		closeAllExcept( nav, 'other-switcher' );
		panel.classList.add( OPEN_CLASS );
		btn.setAttribute( 'aria-expanded', 'true' );
		positionPanel( btn, panel );
		startLivePositioning( nav, btn, panel );

		/**
		 * @hook perflocale:switcher:open
		 * @kind event
		 * @since 1.0.0
		 * @cardinality transition
		 * @cancelable false
		 * @fires After the panel opened.
		 * @context {SwitcherDetail} detail
		 * @property {string} detail.source 'click' (a mouse click or a tap on a touch screen) or 'keyboard' (Enter, Space or an arrow key).
		 * @example document.addEventListener( 'perflocale:switcher:open', ( e ) => console.log( e.detail.current.slug ) );
		 */
		dispatch( nav, 'perflocale:switcher:open', detail( nav, source ), false );

		if ( focusFirstOption ) {
			var first = panel.querySelector( OPTION_SELECTOR );
			if ( first ) { first.focus(); }
		}

		return true;
	}

	function close( nav, btn, panel, returnFocusToTrigger, source ) {
		panel.classList.remove( OPEN_CLASS );
		btn.setAttribute( 'aria-expanded', 'false' );
		stopLivePositioning( nav );

		/**
		 * @hook perflocale:switcher:close
		 * @kind event
		 * @since 1.0.0
		 * @cardinality transition
		 * @cancelable false
		 * @fires After the panel closed, on every path.
		 * @context {SwitcherDetail} detail
		 * @property {string} detail.source 'click' (a mouse click or a tap on a touch screen), 'escape', 'outside', 'focusout', 'other-switcher' or 'hidden'.
		 * @example document.addEventListener( 'perflocale:switcher:close', ( e ) => console.log( e.detail.source ) );
		 */
		dispatch( nav, 'perflocale:switcher:close', detail( nav, source ), false );

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

		setupCount++;

		var st = { btn: btn, panel: panel, instance: nav.id || 'perflocale-switcher-' + setupCount };

		setupRegistry.set( nav, st );
		removeOptionsFromTabOrder( panel );

		panel.addEventListener( 'click', function ( e ) {
			var option = e.target.closest( '.perflocale-dd__option' );

			if ( ! option || option.tagName !== 'A' ) {
				return;
			}

			/**
			 * @hook perflocale:switcher:navigate
			 * @kind event
			 * @since 1.0.0
			 * @cardinality transition
			 * @cancelable true
			 * @fires Before the browser follows a chosen option. preventDefault() stays on the page.
			 * @context {SwitcherDetail} detail
			 * @property {string} detail.source 'click' (a mouse click or a tap on a touch screen) or 'keyboard' (Enter).
			 * @property {Option} detail.target The chosen option.
			 * @property {Language} detail.from = current.
			 * @property {HTMLElement} detail.option The option element.
			 * @property {string} detail.url Its link.
			 * @property {string} detail.slug Its hreflang (BCP 47, e.g. pt-BR).
			 * @example document.addEventListener( 'perflocale:switcher:navigate', ( e ) => gtag( 'event', 'lang', { to: e.detail.target.slug } ) );
			 */
			var allowed = dispatch( nav, 'perflocale:switcher:navigate', Object.defineProperties( detail( nav, e.detail === 0 ? 'keyboard' : 'click' ), {
				target: { enumerable: true, get: function () { return readOption( option ); } },
				from:   { enumerable: true, get: function () { return this.current; } },
				option: { enumerable: true, value: option },
				url:    { enumerable: true, value: option.getAttribute( 'href' ) || '' },
				slug:   { enumerable: true, value: option.getAttribute( 'hreflang' ) || option.getAttribute( 'lang' ) || '' }
			} ), true );

			if ( ! allowed ) {
				e.preventDefault();
			}
		} );

		btn.addEventListener( 'click', function ( e ) {
			var isOpen = panel.classList.contains( OPEN_CLASS );

			if ( isOpen ) {
				close( nav, btn, panel, false, 'click' );
			} else if ( open( nav, btn, panel, e.detail === 0, e.detail === 0 ? 'keyboard' : 'click' ) && e.detail ) {
				// e.detail === 0 is keyboard activation (Enter/Space): open()
				// focuses the first option. A pointer click focuses the trigger
				// here, the focus change its mousedown held back.
				btn.focus();
			}

			e.preventDefault();
			e.stopPropagation();
		} );

		// A press on the closed trigger moves no focus yet, so no other
		// switcher closes on focusout before beforeopen can cancel the open.
		btn.addEventListener( 'mousedown', function ( e ) {
			if ( ! panel.classList.contains( OPEN_CLASS ) ) {
				e.preventDefault();
			}
		} );

		nav.addEventListener( 'keydown', function ( e ) {
			var t      = e.target;
			var isOpen = panel.classList.contains( OPEN_CLASS );
			var opts   = panel.querySelectorAll( OPTION_SELECTOR );

			if ( t === btn ) {
				// A cancelled beforeopen moves no focus.
				if ( e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ' ) {
					if ( ( isOpen || open( nav, btn, panel, false, 'keyboard' ) ) && opts.length ) { opts[ 0 ].focus(); }
					e.preventDefault();
				} else if ( e.key === 'ArrowUp' ) {
					if ( ( isOpen || open( nav, btn, panel, false, 'keyboard' ) ) && opts.length ) { opts[ opts.length - 1 ].focus(); }
					e.preventDefault();
				} else if ( e.key === 'Escape' && isOpen ) {
					close( nav, btn, panel, false, 'escape' );
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
				close( nav, btn, panel, true, 'escape' );
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
					close( nav, btn, panel, false, 'focusout' );
				}
				return;
			}

			( window.requestAnimationFrame || function ( cb ) { return setTimeout( cb, 0 ); } )( function () {
				if ( panel.classList.contains( OPEN_CLASS ) && ! nav.contains( document.activeElement ) ) {
					close( nav, btn, panel, false, 'focusout' );
				}
			} );
		} );

		// For scripts that load after set-up.
		nav.setAttribute( 'data-perflocale-ready', '1' );

		/**
		 * @hook perflocale:switcher:ready
		 * @kind event
		 * @since 1.0.7.4
		 * @cardinality transition
		 * @cancelable false
		 * @fires Once per switcher after set-up; the nav has data-perflocale-ready="1".
		 * @context {SwitcherDetail} detail
		 * @property {string} detail.source 'setup'.
		 * @example document.addEventListener( 'perflocale:switcher:ready', ( e ) => console.log( e.detail.instance ) );
		 */
		dispatch( nav, 'perflocale:switcher:ready', detail( nav, 'setup' ), false );
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
		closeAllExcept( null, 'outside' );
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
