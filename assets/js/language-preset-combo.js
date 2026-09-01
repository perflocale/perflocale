/**
 * Searchable language-preset combobox for the Add/Edit Language admin form.
 *
 * Vanilla JS (no dependencies). Replaces the native `<select id="perflocale-preset">`
 * with a text input + filterable list. Fills the Add Language form fields
 * (slug, locale, name, native_name, flag, text_direction, date_format,
 * time_format) when the user picks an option, identically to the original
 * `<select>` change-handler. Falls back gracefully: if JS fails to boot,
 * the original `<select>` stays usable.
 *
 * Filter matches any of: english name, native name, locale, slug, flag.
 *
 * Sorted: popular languages first (a curated list mirroring real WP-admin
 * usage frequency: en, fr, de, es, it, pt-br, zh-cn, zh-tw, ja, ko, ar,
 * ru, hi, nl, pl, tr, sv); then alphabetical by english name. The order
 * is computed server-side and shipped via `data-popular="1"` markers, so
 * this script only does presentation + filter.
 *
 * Loads only on the Languages add/edit screen (via Assets enqueue gate).
 */
( function () {
	'use strict';

	function init() {
		var combo = document.querySelector( '[data-perflocale-combo]' );
		if ( ! combo ) {
			return;
		}

		var input = combo.querySelector( '.perflocale-combo__input' );
		var list  = combo.querySelector( '.perflocale-combo__list' );
		var status = combo.querySelector( '.perflocale-combo__status' );
		var i18n = ( window.perflocaleCombo || {} ).i18n || {};
		if ( ! input || ! list ) {
			return;
		}

		var allOptions = Array.prototype.slice.call(
			list.querySelectorAll( '.perflocale-combo__option' )
		);

		// Group labels (".perflocale-combo__group-label") are siblings that
		// precede their options. We hide a label when none of the options
		// between it and the next label (or end of list) are visible.
		var groupLabels = Array.prototype.slice.call(
			list.querySelectorAll( '.perflocale-combo__group-label' )
		);

		// Map of dataset payloads keyed by row index, kept once so the
		// keyboard/click handlers don't repeatedly walk attributes.
		var payloads = allOptions.map( function ( el ) {
			return {
				el: el,
				slug:        ( el.getAttribute( 'data-slug' )        || '' ).toLowerCase(),
				locale:      ( el.getAttribute( 'data-locale' )      || '' ).toLowerCase(),
				name:        ( el.getAttribute( 'data-name' )        || '' ).toLowerCase(),
				native:      ( el.getAttribute( 'data-native' )      || '' ).toLowerCase(),
				flag:        ( el.getAttribute( 'data-flag' )        || '' ).toLowerCase(),
				dir:         ( el.getAttribute( 'data-dir' )         || 'ltr' ),
				dateFormat:  ( el.getAttribute( 'data-date-format' ) || '' ),
				timeFormat:  ( el.getAttribute( 'data-time-format' ) || '' ),
				rawName:     ( el.getAttribute( 'data-name' )        || '' ),
				rawNative:   ( el.getAttribute( 'data-native' )      || '' ),
				rawLocale:   ( el.getAttribute( 'data-locale' )      || '' ),
			};
		} );

		var activeIndex = -1;
		var visibleCount = payloads.length;

		// Field references — resolved once. We don't re-query per click.
		var fields = {
			slug:    document.getElementById( 'perflocale-slug' ),
			locale:  document.getElementById( 'perflocale-locale' ),
			name:    document.getElementById( 'perflocale-name' ),
			native:  document.getElementById( 'perflocale-native-name' ),
			flag:    document.getElementById( 'perflocale-flag' ),
			dir:     document.getElementById( 'perflocale-text-direction' ),
			date:    document.getElementById( 'perflocale-date-format' ),
			time:    document.getElementById( 'perflocale-time-format' ),
		};

		function open() {
			list.hidden = false;
			combo.classList.add( 'is-open' );
			// aria-expanded belongs on the role="combobox" element (the input),
			// not the wrapper — screen readers ignore it on the un-roled div.
			input.setAttribute( 'aria-expanded', 'true' );
		}

		function close() {
			list.hidden = true;
			combo.classList.remove( 'is-open' );
			input.setAttribute( 'aria-expanded', 'false' );
			setActive( -1 );
		}

		function updateStatus() {
			if ( ! status ) {
				return;
			}
			if ( visibleCount === 0 ) {
				status.textContent = i18n.noMatches || 'No languages match — try a different search.';
				status.style.display = 'block';
			} else {
				status.style.display = 'none';
			}
		}

		function applyFilter( query ) {
			query = query.trim().toLowerCase();
			visibleCount = 0;

			for ( var i = 0; i < payloads.length; i++ ) {
				var p = payloads[ i ];
				var match = query === '' ||
					p.slug.indexOf( query ) !== -1 ||
					p.locale.indexOf( query ) !== -1 ||
					p.name.indexOf( query ) !== -1 ||
					p.native.indexOf( query ) !== -1 ||
					p.flag.indexOf( query ) !== -1;

				p.el.hidden = ! match;
				if ( match ) {
					visibleCount++;
				}
			}

			// Hide each group label when no following sibling option (up to
			// the next group label) is visible. Walking nextElementSibling
			// keeps this O(N) without per-label DOM queries.
			for ( var g = 0; g < groupLabels.length; g++ ) {
				var label = groupLabels[ g ];
				var sibling = label.nextElementSibling;
				var anyVisible = false;
				while (
					sibling &&
					! sibling.classList.contains( 'perflocale-combo__group-label' )
				) {
					if (
						sibling.classList.contains( 'perflocale-combo__option' ) &&
						! sibling.hidden
					) {
						anyVisible = true;
						break;
					}
					sibling = sibling.nextElementSibling;
				}
				label.hidden = ! anyVisible;
			}

			// Reset highlight to first visible match when filter narrows.
			setActive( -1 );
			updateStatus();
		}

		function setActive( index ) {
			if ( activeIndex >= 0 && payloads[ activeIndex ] ) {
				payloads[ activeIndex ].el.classList.remove( 'is-active' );
				payloads[ activeIndex ].el.setAttribute( 'aria-selected', 'false' );
			}
			activeIndex = index;
			if ( activeIndex >= 0 && payloads[ activeIndex ] ) {
				var el = payloads[ activeIndex ].el;
				el.classList.add( 'is-active' );
				el.setAttribute( 'aria-selected', 'true' );
				// Point the combobox at the active option so AT announces it.
				input.setAttribute( 'aria-activedescendant', el.id );

				// Scroll into view only if needed — avoid the jumpy "always
				// scroll" feel.
				var elTop = el.offsetTop;
				var elBottom = elTop + el.offsetHeight;
				var listTop = list.scrollTop;
				var listBottom = listTop + list.clientHeight;
				if ( elTop < listTop ) {
					list.scrollTop = elTop;
				} else if ( elBottom > listBottom ) {
					list.scrollTop = elBottom - list.clientHeight;
				}
			} else {
				input.removeAttribute( 'aria-activedescendant' );
			}
		}

		function visibleIndices() {
			var idx = [];
			for ( var i = 0; i < payloads.length; i++ ) {
				if ( ! payloads[ i ].el.hidden ) {
					idx.push( i );
				}
			}
			return idx;
		}

		function moveActive( direction ) {
			var visible = visibleIndices();
			if ( visible.length === 0 ) {
				return;
			}
			var pos = visible.indexOf( activeIndex );
			if ( direction > 0 ) {
				pos = pos < 0 ? 0 : Math.min( pos + 1, visible.length - 1 );
			} else {
				pos = pos < 0 ? visible.length - 1 : Math.max( pos - 1, 0 );
			}
			setActive( visible[ pos ] );
		}

		function selectPayload( p ) {
			if ( fields.slug )   fields.slug.value   = p.slug;
			if ( fields.locale ) fields.locale.value = p.rawLocale;
			if ( fields.name )   fields.name.value   = p.rawName;
			if ( fields.native ) fields.native.value = p.rawNative;
			if ( fields.flag )   fields.flag.value   = p.flag;
			if ( fields.dir )    fields.dir.value    = p.dir;
			if ( fields.date )   fields.date.value   = p.dateFormat;
			if ( fields.time )   fields.time.value   = p.timeFormat;

			// Programmatic value-set doesn't fire input/change. Dispatch
			// `input` on the slug field so the rename-checkbox toggle JS
			// (which listens for `input`) reveals the right candidate.
			if ( fields.slug ) {
				fields.slug.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}

			// Mirror the visible label back into the combo input so the
			// user sees what they picked.
			input.value = p.rawName + ' — ' + p.rawNative;
			close();
		}

		function selectActive() {
			if ( activeIndex >= 0 && payloads[ activeIndex ] ) {
				selectPayload( payloads[ activeIndex ] );
				return;
			}
			// No keyboard highlight — pick the first visible row if any.
			var visible = visibleIndices();
			if ( visible.length > 0 ) {
				selectPayload( payloads[ visible[ 0 ] ] );
			}
		}

		// Wire events.
		input.addEventListener( 'focus', open );
		input.addEventListener( 'click', open );
		input.addEventListener( 'input', function () {
			open();
			applyFilter( input.value );
		} );

		input.addEventListener( 'keydown', function ( ev ) {
			switch ( ev.key ) {
				case 'ArrowDown':
					ev.preventDefault();
					open();
					moveActive( 1 );
					break;
				case 'ArrowUp':
					ev.preventDefault();
					open();
					moveActive( -1 );
					break;
				case 'Enter':
					if ( ! list.hidden ) {
						ev.preventDefault();
						selectActive();
					}
					break;
				case 'Escape':
					if ( ! list.hidden ) {
						ev.preventDefault();
						close();
						input.blur();
					}
					break;
			}
		} );

		list.addEventListener( 'click', function ( ev ) {
			var li = ev.target.closest( '.perflocale-combo__option' );
			if ( ! li ) return;
			var i = allOptions.indexOf( li );
			if ( i >= 0 ) {
				selectPayload( payloads[ i ] );
			}
		} );

		list.addEventListener( 'mouseover', function ( ev ) {
			var li = ev.target.closest( '.perflocale-combo__option' );
			if ( ! li ) return;
			var i = allOptions.indexOf( li );
			if ( i >= 0 ) {
				setActive( i );
			}
		} );

		// Click outside closes.
		document.addEventListener( 'click', function ( ev ) {
			if ( ! combo.contains( ev.target ) ) {
				close();
			}
		} );

		// Initial render: no query, full list shown when opened.
		applyFilter( '' );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
