/**
 * Translations page — MT cost estimates.
 *
 * Two responsibilities, both estimate-first so no provider spend happens
 * without the operator seeing real numbers:
 *
 * 1. Bulk action confirm: when the "Translate via <provider>" bulk action is
 *    applied, fetch a character/cost estimate for the checked rows and ask
 *    for confirmation before the form actually submits. If the estimate
 *    endpoint is unreachable the flow degrades to a plain confirm — the
 *    feature never hard-depends on it.
 *
 * 2. "Translate the entire site" panel: the Start button stays disabled
 *    until an estimate has been fetched and is within the monthly budget.
 */
( function () {
	'use strict';

	var cfg = window.perflocaleTrMt || {};

	function restPost( url, body ) {
		return window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || ''
			},
			body: JSON.stringify( body || {} )
		} ).then( function ( res ) {
			return res.json().then( function ( json ) {
				if ( ! res.ok ) { throw json; }
				return json && json.data !== undefined ? json.data : json;
			} );
		} );
	}

	function fmt( n ) {
		try { return new Intl.NumberFormat().format( n ); } catch ( _ ) { return String( n ); }
	}

	function targetIdsFor( slug ) {
		var targets = cfg.targets || [];
		if ( ! slug || slug === 'all' ) {
			return targets.map( function ( t ) { return t.id; } );
		}
		return targets.filter( function ( t ) { return t.slug === slug; } ).map( function ( t ) { return t.id; } );
	}

	// ---- 1. Bulk-action estimate + confirm -------------------------------
	function initBulkConfirm() {
		var form = document.querySelector( '[data-perflocale-bulk-form]' );
		var select = document.querySelector( '[data-perflocale-bulk-select]' );
		var metaToggle = document.querySelector( '[data-perflocale-bulk-meta]' );
		if ( ! form || ! select ) { return; }

		// The meta toggle only matters for the MT action — show it then.
		if ( metaToggle ) {
			var syncMetaVisibility = function () {
				metaToggle.hidden = select.value !== 'mt_translate';
			};
			select.addEventListener( 'change', syncMetaVisibility );
			syncMetaVisibility();
		}

		var confirmed = false;

		form.addEventListener( 'submit', function ( ev ) {
			if ( confirmed || select.value !== 'mt_translate' || ! cfg.estimateUrl ) {
				return; // other actions / already confirmed / no estimate support
			}

			var ids = Array.prototype.map.call(
				document.querySelectorAll( 'input[name="ids[]"]:checked' ),
				function ( el ) { return parseInt( el.value, 10 ); }
			).filter( function ( n ) { return n > 0; } );

			if ( ! ids.length ) { return; } // server shows its own "pick rows" notice

			var langSelect = document.querySelector( '[data-perflocale-bulk-value-target-lang]' );
			var langIds = targetIdsFor( langSelect ? langSelect.value : 'all' );
			var includeMeta = ! ( metaToggle && metaToggle.hidden ) && !! ( metaToggle && metaToggle.querySelector( 'input' ) && metaToggle.querySelector( 'input' ).checked );

			ev.preventDefault();

			// Busy state: without it, nothing tells the user the click
			// registered during the estimate round-trip, and a second
			// click double-fires the flow.
			var applyBtn = ( ev.submitter && ev.submitter.disabled !== undefined )
				? ev.submitter
				: form.querySelector( 'input[type="submit"], button[type="submit"]' );
			var unbusy = function () {
				if ( applyBtn ) { applyBtn.disabled = false; }
			};
			if ( applyBtn ) { applyBtn.disabled = true; }

			restPost( cfg.estimateUrl, {
				kind: 'posts',
				post_ids: ids,
				target_lang_ids: langIds,
				include_meta: includeMeta
			} ).then( function ( est ) {
				var proceed;
				if ( est.would_exceed ) {
					window.alert( ( cfg.i18n.overBudget || '' ).replace( '%1$s', fmt( est.chars ) ).replace( '%2$s', fmt( est.monthly_remaining ) ) );
					unbusy();
					return;
				}
				proceed = window.confirm(
					( cfg.i18n.confirmEstimate || '%1$s / %2$s / %3$s' )
						.replace( '%1$s', fmt( est.items ) )
						.replace( '%2$s', fmt( est.chars ) )
						.replace( '%3$s', fmt( est.skipped_existing ) )
				);
				if ( proceed ) {
					confirmed = true;
					form.submit();
					return; // keep disabled — the page is navigating.
				}
				unbusy();
			} ).catch( function () {
				// Estimate unavailable: degrade to a plain confirm.
				if ( window.confirm( cfg.i18n.confirmPlain || 'Machine-translate the selected items?' ) ) {
					confirmed = true;
					form.submit();
					return;
				}
				unbusy();
			} );
		} );
	}

	// ---- 2. Site-wide panel ----------------------------------------------
	function initSitePanel() {
		var panel = document.querySelector( '[data-perflocale-site-translate]' );
		if ( ! panel || ! cfg.bulkUrl ) { return; }

		var estBtn = panel.querySelector( '[data-perflocale-site-estimate]' );
		var startBtn = panel.querySelector( '[data-perflocale-site-start]' );
		var result = panel.querySelector( '[data-perflocale-site-result]' );

		function selection() {
			return {
				langIds: Array.prototype.map.call( panel.querySelectorAll( '[data-perflocale-site-lang]:checked' ), function ( el ) { return parseInt( el.value, 10 ); } ),
				types: Array.prototype.map.call( panel.querySelectorAll( '[data-perflocale-site-type]:checked' ), function ( el ) { return el.value; } ),
				includeMeta: !! panel.querySelector( 'input[name="include_meta"]:checked' )
			};
		}

		// Any selection change invalidates the previous estimate — and any
		// estimate still in flight: without the token, a response computed
		// for the OLD selection would land after the change, print stale
		// numbers, and re-enable Start for a selection that was never
		// estimated (the server re-estimates on start, but the user acts
		// on what they read).
		var estToken = 0;

		panel.addEventListener( 'change', function () {
			estToken++;
			startBtn.disabled = true;
			result.textContent = '';
		} );

		estBtn.addEventListener( 'click', function () {
			var sel = selection();
			if ( ! sel.langIds.length || ! sel.types.length ) {
				result.textContent = cfg.i18n.pickSelection || '';
				return;
			}
			var token = ++estToken;
			estBtn.disabled = true;
			result.textContent = cfg.i18n.estimating || '…';

			restPost( cfg.bulkUrl, {
				site_wide: true,
				estimate_only: true,
				post_types: sel.types,
				target_lang_ids: sel.langIds,
				include_meta: sel.includeMeta
			} ).then( function ( est ) {
				estBtn.disabled = false;
				if ( token !== estToken ) {
					return; // Selection changed mid-flight — estimate is stale.
				}
				if ( est.would_exceed ) {
					result.textContent = ( cfg.i18n.overBudget || '' ).replace( '%1$s', fmt( est.chars ) ).replace( '%2$s', fmt( est.monthly_remaining ) );
					startBtn.disabled = true;
					return;
				}
				result.textContent = ( cfg.i18n.estimateResult || '%1$s / %2$s / %3$s' )
					.replace( '%1$s', fmt( est.items ) )
					.replace( '%2$s', fmt( est.chars ) )
					.replace( '%3$s', fmt( est.skipped_existing ) );
				startBtn.disabled = false;
			} ).catch( function ( err ) {
				estBtn.disabled = false;
				if ( token !== estToken ) {
					return;
				}
				result.textContent = ( err && err.message ) || cfg.i18n.genericError || 'Error';
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () { initBulkConfirm(); initSitePanel(); } );
	} else {
		initBulkConfirm();
		initSitePanel();
	}
} )();
