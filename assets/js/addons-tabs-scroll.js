/**
 * Addons page — scroll indicator for the filter tab strip.
 *
 * The strip is one non-wrapping row (see assets/css/admin-addons.css for why),
 * so on a narrow screen it scrolls sideways. The native scrollbar cannot be the
 * affordance for that: iOS and Android draw scrollbars as transient overlays
 * that disappear at rest, and iOS ignores ::-webkit-scrollbar entirely — so on
 * exactly the devices that need the hint there is nothing to see. The CSS hides
 * the native bar and this draws a small one that behaves the same everywhere.
 *
 * Progressive enhancement: the indicator ships hidden and is only revealed once
 * a real overflow has been measured, so with this script blocked the page shows
 * no bar rather than a misleading one.
 */
( function () {
	'use strict';

	var wrap = document.querySelector( '.perflocale-addons-tabs-wrap' );

	if ( ! wrap ) {
		return;
	}

	var scroller = wrap.querySelector( '.perflocale-addons-tabs-scroll' );
	var bar      = wrap.querySelector( '.perflocale-addons-tabs-bar' );
	var thumb    = bar ? bar.firstElementChild : null;

	if ( ! scroller || ! thumb ) {
		return;
	}

	function update() {
		var max = scroller.scrollWidth - scroller.clientWidth;

		// 1px of slack: sub-pixel layout routinely leaves a fractional
		// difference on a strip that actually fits, and a bar that appears for
		// half a pixel of "overflow" is worse than no bar.
		if ( max <= 1 ) {
			bar.className = 'perflocale-addons-tabs-bar';
			return;
		}

		bar.className = 'perflocale-addons-tabs-bar is-visible';

		var ratio = scroller.clientWidth / scroller.scrollWidth;

		// scrollLeft is negative in RTL on Gecko and on Blink, and positive on
		// older WebKit. Absolute value normalises all three; the thumb is then
		// positioned from its anchored physical side below.
		var progress = Math.min( 1, Math.abs( scroller.scrollLeft ) / max );

		// Pixels + translateX rather than a logical inset: inset-inline-start
		// only landed in Chrome 87 and is dropped silently on anything older,
		// which left the thumb rendered but frozen at the start. The sheets
		// anchor the thumb to a physical side (left in LTR, right in RTL) and
		// the sign below follows the computed direction, so both work back to
		// very old engines.
		var track   = bar.clientWidth;
		var thumbPx = Math.max( 24, ratio * track );
		var offset  = progress * ( track - thumbPx );
		var rtl     = window.getComputedStyle( bar ).direction === 'rtl';

		thumb.style.width     = thumbPx + 'px';
		thumb.style.transform = 'translateX(' + ( rtl ? -offset : offset ) + 'px)';
	}

	var ticking = false;

	function schedule() {
		if ( ticking ) {
			return;
		}

		ticking = true;

		window.requestAnimationFrame( function () {
			ticking = false;
			update();
		} );
	}

	update();

	scroller.addEventListener( 'scroll', schedule, { passive: true } );
	window.addEventListener( 'resize', schedule );

	// Bring the selected filter into view. Without this, choosing a filter near
	// the end of the strip reloads the page with that tab scrolled off-screen,
	// and the strip looks like it forgot the choice. `block: 'nearest'` keeps it
	// from scrolling the page vertically.
	var active = scroller.querySelector( '.perflocale-addons-tab--active' );

	if ( active && typeof active.scrollIntoView === 'function' ) {
		try {
			active.scrollIntoView( { block: 'nearest', inline: 'nearest' } );
		} catch ( e ) {
			// Older engines only accept a boolean and would scroll the page.
			// Skipping is the safe failure: the strip simply starts at its
			// inline start.
		}
	}
}() );
