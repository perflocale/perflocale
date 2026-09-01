/**
 * PerfLocale — WooCommerce settings subtab JS.
 *
 * Two interactive widgets on the WooCommerce subtab:
 *
 *   1. "Sync Now" button — POST to admin-ajax.php
 *      (action=perflocale_sync_exchange_rates), update the per-language
 *      rate inputs from the response, render status message.
 *
 *   2. "Create Page Translations" button — POST to admin-ajax.php
 *      (action=perflocale_create_wc_pages), drive a progress bar while
 *      it runs, render the per-page status table on completion.
 *
 * Show/hide of conditional rows (currency-per-lang toggle drives
 * exchange-rate matrix visibility; auto-sync toggle drives provider /
 * interval / status visibility; provider select drives API-key row
 * visibility) is handled by the generic addon-settings-conditional.js
 * via data-perflocale-show-if attrs on the rendered <tr> rows.
 *
 * Localised data on `perflocaleWcData` is set by the PHP enqueue: the
 * two nonces + an i18n bag. If it's absent the script no-ops gracefully.
 */
( function () {
	'use strict';

	if ( typeof perflocaleWcData === 'undefined' ) {
		return;
	}

	var d = perflocaleWcData;

	// Escape dynamic text before it ever reaches innerHTML. Server responses
	// (e.g. a provider error message, or page-creation detail labels) are not
	// guaranteed HTML-safe, so mirror the helper used in jobs.js.
	var escapeHtml = function ( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return ( {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#39;'
			} )[ c ];
		} );
	};

	// ── Auto-sync read-only toggling for rate inputs ─────────────
	// When auto-sync is ON, the per-language rate inputs become
	// readonly (the live API owns them). When OFF, users edit them
	// manually. The conditional-fields JS handles row visibility;
	// this one block handles the readonly attribute on the inputs
	// inside the always-visible currency-matrix row.
	function applyRateReadonly() {
		var autoSyncToggle = document.getElementById( 'perflocale-auto-sync-toggle' );
		var currencyToggle = document.getElementById( 'perflocale-wc-currency-toggle' );

		var readonly = (
			autoSyncToggle && autoSyncToggle.checked &&
			currencyToggle && currencyToggle.checked
		);

		document.querySelectorAll( '.perflocale-rate-input' ).forEach( function ( input ) {
			input.readOnly = !! readonly;
		} );
	}

	// ── Sync Now button ───────────────────────────────────────────
	function bindSyncNow() {
		var btn = document.getElementById( 'perflocale-sync-now' );
		if ( ! btn ) { return; }

		btn.addEventListener( 'click', function () {
			var spinner = document.getElementById( 'perflocale-sync-spinner' );
			var result  = document.getElementById( 'perflocale-sync-result' );
			btn.disabled = true;
			if ( spinner ) spinner.classList.add( 'is-active' );
			if ( result )  { result.textContent = ''; result.style.color = ''; }

			var data = new FormData();
			data.append( 'action', 'perflocale_sync_exchange_rates' );
			data.append( '_nonce', d.syncRatesNonce );

			fetch( ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( resp ) {
					if ( spinner ) spinner.classList.remove( 'is-active' );
					btn.disabled = false;

					if ( ! resp.success ) {
						if ( result ) {
							result.textContent = ( resp.data && resp.data.message ) || d.i18nSyncFailed;
							result.style.color = '#d63638';
						}
						return;
					}

					if ( result ) {
						result.textContent = resp.data.message || d.i18nRatesUpdated;
						result.style.color = '#00a32a';
					}

					// Apply the returned rates back into each language's
					// rate input. Match each input's `name` attribute to
					// the corresponding currency_code input so we know
					// which row goes with which language.
					if ( resp.data.rates ) {
						document.querySelectorAll( '.perflocale-rate-input' ).forEach( function ( input ) {
							var name  = input.getAttribute( 'name' ) || '';
							var match = name.match( /wc_currencies\[([^\]]+)\]\[exchange_rate\]/ );
							if ( ! match ) { return; }

							var slug      = match[ 1 ];
							var codeInput = document.querySelector( 'input[name="wc_currencies[' + slug + '][currency_code]"]' );

							if ( codeInput && resp.data.rates[ codeInput.value.toUpperCase() ] ) {
								input.value = parseFloat( resp.data.rates[ codeInput.value.toUpperCase() ] ).toFixed( 6 );
							}
						} );
					}
				} )
				.catch( function () {
					if ( spinner ) spinner.classList.remove( 'is-active' );
					btn.disabled = false;
					if ( result ) {
						result.textContent = d.i18nNetworkError;
						result.style.color = '#d63638';
					}
				} );
		} );
	}

	// ── Create WC Page Translations button ───────────────────────
	function bindCreatePages() {
		var btn = document.getElementById( 'perflocale-create-wc-pages' );
		if ( ! btn ) { return; }

		btn.addEventListener( 'click', function () {
			var progress = document.getElementById( 'perflocale-wc-progress' );
			var bar      = document.getElementById( 'perflocale-wc-bar' );
			var status   = document.getElementById( 'perflocale-wc-status' );
			var percent  = document.getElementById( 'perflocale-wc-percent' );
			var result   = document.getElementById( 'perflocale-wc-pages-result' );

			btn.disabled = true;
			if ( progress ) progress.style.display = 'block';
			if ( bar )      { bar.style.width = '0'; bar.style.background = '#2271b1'; }
			if ( status )   status.textContent = d.i18nCreatingPages;
			if ( percent )  percent.textContent = '';
			if ( result )   result.innerHTML = '';

			// Indeterminate-ish progress: tick the bar up to 90% while we
			// wait for the response; jump to 100% when it arrives.
			var pct = 0;
			var pInterval = setInterval( function () {
				pct = Math.min( pct + Math.random() * 15, 90 );
				if ( bar )     bar.style.width    = pct + '%';
				if ( percent ) percent.textContent = Math.round( pct ) + '%';
			}, 300 );

			var data = new FormData();
			data.append( 'action', 'perflocale_create_wc_pages' );
			data.append( '_nonce', d.createPagesNonce );

			fetch( ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( resp ) {
					clearInterval( pInterval );
					if ( bar )     bar.style.width    = '100%';
					if ( percent ) percent.textContent = '100%';
					btn.disabled = false;
					if ( ! result ) { return; }

					if ( ! resp.success ) {
						if ( bar )    bar.style.background = '#d63638';
						if ( status ) status.textContent   = d.i18nFailed;
						result.innerHTML = '<p style="color:#d63638;margin:0;">' + escapeHtml( ( resp.data && resp.data.message ) || d.i18nFailedDot ) + '</p>';
						return;
					}

					if ( bar )    bar.style.background = '#00a32a';
					if ( status ) status.textContent   = resp.data.message || d.i18nDone;

					var html = '';
					if ( resp.data.details && resp.data.details.length ) {
						html += '<table class="widefat striped" style="max-width:420px;"><tbody>';
						resp.data.details.forEach( function ( line ) {
							var parts = line.split( ': ' );
							var label = parts[ 0 ] || '';
							var st    = parts[ 1 ] || '';
							var isNew = st.indexOf( 'created' ) !== -1;
							var color = isNew ? '#00a32a' : '#6b7280';
							var icon  = isNew ? '&#10003;' : '&#8212;';
							html += '<tr><td style="padding:4px 8px;">' + escapeHtml( label ) + '</td>';
							html += '<td style="padding:4px 8px;color:' + color + ';white-space:nowrap;">' + icon + ' ' + escapeHtml( st ) + '</td></tr>';
						} );
						html += '</tbody></table>';
					}
					result.innerHTML = html;
				} )
				.catch( function () {
					clearInterval( pInterval );
					if ( bar )     { bar.style.width = '100%'; bar.style.background = '#d63638'; }
					if ( percent ) percent.textContent = '';
					if ( status )  status.textContent  = d.i18nNetworkErr;
					btn.disabled = false;
				} );
		} );
	}

	function init() {
		applyRateReadonly();

		// Re-apply readonly on changes to either toggle.
		var autoSyncToggle = document.getElementById( 'perflocale-auto-sync-toggle' );
		var currencyToggle = document.getElementById( 'perflocale-wc-currency-toggle' );
		if ( autoSyncToggle ) autoSyncToggle.addEventListener( 'change', applyRateReadonly );
		if ( currencyToggle ) currencyToggle.addEventListener( 'change', applyRateReadonly );

		bindSyncNow();
		bindCreatePages();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
