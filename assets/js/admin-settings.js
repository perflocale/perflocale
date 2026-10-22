/**
 * PerfLocale - admin Settings page progressive enhancements.
 *
 * Switcher tab: rows tagged with `data-perflocale-show-when-display="<value>"`
 * are shown only when the Display Mode select matches `<value>`. No-ops on
 * tabs where the trigger select isn't present.
 *
 * URL & Routing tab: the per-language domain table
 * (`#perflocale-domain-config`) is shown only while the "Per-language domain"
 * URL mode is selected. The server renders it in every mode, hidden outside
 * domain mode, so a save keeps the stored domains; this only follows the
 * radio. No-ops on tabs without the URL mode radios.
 *
 * URL & Routing tab: the GeoIP rows (`.perflocale-geo-field`) follow the
 * GeoIP checkbox (`#perflocale-geo-enabled`).
 *
 * Export & Import tab: "Select All" (`#perflocale-export-all`) ticks or
 * clears every export section, and follows them when they change.
 *
 * Clipboard / confirm / submit-busy handlers for data-perflocale-* attrs
 * live in admin-actions.js (delegated, loaded on every plugin admin page).
 */
( function () {
	'use strict';

	function syncDisplayRows() {
		var displaySelect = document.getElementById( 'perflocale-switcher-display' );

		if ( ! displaySelect ) {
			return;
		}

		var rows = document.querySelectorAll( '[data-perflocale-show-when-display]' );

		function apply() {
			var current = displaySelect.value;

			rows.forEach( function ( row ) {
				var needed = row.getAttribute( 'data-perflocale-show-when-display' );
				row.style.display = ( needed === current ) ? '' : 'none';
			} );
		}

		displaySelect.addEventListener( 'change', apply );
		apply();
	}

	function syncDomainConfig() {
		var radios = document.querySelectorAll( 'input[name="url_mode"]' );
		var config = document.getElementById( 'perflocale-domain-config' );

		if ( ! radios.length || ! config ) {
			return;
		}

		radios.forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				config.style.display = ( this.value === 'domain' ) ? '' : 'none';
			} );
		} );
	}

	function syncGeoFields() {
		var geo = document.getElementById( 'perflocale-geo-enabled' );

		if ( ! geo ) {
			return;
		}

		function toggle() {
			var on = geo.checked;
			document.querySelectorAll( '.perflocale-geo-field' ).forEach( function ( tr ) {
				tr.style.display = on ? '' : 'none';
			} );
		}

		geo.addEventListener( 'change', toggle );
		toggle();
	}

	function syncExportSelectAll() {
		var allCb = document.getElementById( 'perflocale-export-all' );
		var boxes = document.querySelectorAll( '.perflocale-export-section' );

		if ( ! allCb ) {
			return;
		}

		allCb.addEventListener( 'change', function () {
			boxes.forEach( function ( cb ) {
				cb.checked = allCb.checked;
			} );
		} );

		boxes.forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				allCb.checked = [].every.call( boxes, function ( c ) {
					return c.checked;
				} );
			} );
		} );
	}

	function init() {
		syncDisplayRows();
		syncDomainConfig();
		syncGeoFields();
		syncExportSelectAll();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
