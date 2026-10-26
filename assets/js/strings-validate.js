/**
 * Sprintf-placeholder validation for the Strings admin page.
 *
 * Two consumers share the extractor:
 *
 *   1. The Edit Translation MODAL - live-highlights placeholders as the
 *      user types. Each chip turns green when present, red when missing,
 *      amber when over-counted. Clicking a missing chip inserts the token
 *      at the textarea's caret. Clicking Apply on a non-clean state shows
 *      a confirm dialog (still allows save - translators sometimes
 *      legitimately drop a placeholder when collapsing args). This is the
 *      authoritative validation gate for translators editing one string
 *      at a time.
 *
 *   2. The PER-ROW INLINE indicator - paints a red border + ⚠ icon on any
 *      row whose translation is missing a source placeholder, updated on
 *      every keystroke. Surfaces issues in the table view without forcing
 *      the user into the modal. Save-time gating intentionally relies on
 *      the inline visibility rather than a blocking page-submit confirm:
 *      the previous form-level confirm() dialog proved more disruptive
 *      than helpful (most users dismissed without reading), so it was
 *      removed in favour of the always-visible inline marker.
 *
 * Regex must match `\PerfLocale\Util\SprintfTokens::PATTERN` in PHP exactly so the
 * server and client never disagree.
 *
 * After the extractor below: the PO notice clean-up (only after a PO notice),
 * then both consumers.
 */
( function ( global ) {
	'use strict';

	// Mirrors \PerfLocale\Util\SprintfTokens::PATTERN. Zeros before the width
	// are flags and a width starts 1-9: one parse per input, linear time.
	var TOKEN_RE = /%(%|(?:\d+\$)?[-+ #0']*(?:[1-9]\d*|\*)?(?:\.(?:\d+|\*))?[bcdeEfFgGoOsuxX])/g;

	function extractPlaceholders( text ) {
		if ( ! text ) {
			return [];
		}
		var out = [];
		var seen = {};
		var m;
		// Reset lastIndex on a stateful /g regex before reuse.
		TOKEN_RE.lastIndex = 0;
		while ( ( m = TOKEN_RE.exec( text ) ) !== null ) {
			if ( m[ 1 ] === '%' ) {
				continue; // %% literal escape - skip
			}
			if ( ! seen[ m[ 0 ] ] ) {
				seen[ m[ 0 ] ] = true;
				out.push( m[ 0 ] );
			}
		}
		return out;
	}

	// Count occurrences of a literal substring without regex escaping
	// hassles (placeholders contain regex metacharacters like $).
	function countOccurrences( haystack, needle ) {
		if ( ! haystack || ! needle ) return 0;
		var count = 0;
		var idx = haystack.indexOf( needle );
		while ( idx !== -1 ) {
			count++;
			idx = haystack.indexOf( needle, idx + needle.length );
		}
		return count;
	}

	function comparePlaceholders( source, translation ) {
		var tokens = extractPlaceholders( source );
		var states = {};
		tokens.forEach( function ( token ) {
			var srcCount = countOccurrences( source, token );
			var trgCount = countOccurrences( translation, token );
			if ( trgCount === 0 || trgCount < srcCount ) {
				states[ token ] = 'missing';
			} else if ( trgCount > srcCount ) {
				states[ token ] = 'extra';
			} else {
				states[ token ] = 'present';
			}
		} );
		return { tokens: tokens, states: states };
	}

	// Public API.
	global.perflocaleStrValidate = {
		extract: extractPlaceholders,
		compare: comparePlaceholders,
		count: countOccurrences,
	};
} )( window );

/*
 * Strings screen, after a PO import or export notice has rendered
 * (window.perflocaleStrPo, localized only then): drop the notice's query
 * arguments from the address bar, so a reload does not show the notice again
 * and pagination links stay clean.
 */
if ( window.perflocaleStrPo ) {
( function () {
	'use strict';

	if ( ! window.history || ! window.history.replaceState ) {
		return;
	}

	var url = new URL( window.location.href );

	[ 'po_message', 'po_upload_err', 'imported', 'skipped', 'errors' ].forEach( function ( key ) {
		url.searchParams.delete( key );
	} );

	window.history.replaceState( {}, document.title, url.toString() );
}() );
}

/*
 * Strings screen: the Edit Translation modal and the per-row placeholder
 * check, both on window.perflocaleStrValidate above. The modal highlights the
 * placeholders live; the row check marks a row input whose value is missing a
 * source placeholder. Data: window.perflocaleStrI18n (wp_localize_script on
 * this script's handle, printed where the screen renders the modal).
 */
( function () {
	'use strict';

	// Modal.
	( function () {
		var modal = document.getElementById( 'perflocale-str-modal' );
		if ( ! modal ) return;
		var textarea = document.getElementById( 'perflocale-str-modal-textarea' );
		var originalEl = document.getElementById( 'perflocale-str-modal-original' );
		var contextEl = document.getElementById( 'perflocale-str-modal-context' );
		var contextWrap = document.getElementById( 'perflocale-str-modal-context-wrap' );
		var phWrap = document.getElementById( 'perflocale-str-modal-ph-wrap' );
		var phList = document.getElementById( 'perflocale-str-modal-ph' );
		var phStatus = document.getElementById( 'perflocale-str-modal-ph-status' );
		var applyBtn = document.getElementById( 'perflocale-str-modal-apply' );
		var langLabel = document.getElementById( 'perflocale-str-modal-lang-label' );
		var targetField = null;
		var modalOriginal = '';
		var validateTimer = null;

		// Render chips for `tokens`, attach click-to-insert handlers.
		function renderChips( tokens ) {
			phList.innerHTML = '';
			tokens.forEach( function ( ph ) {
				var chip = document.createElement( 'button' );
				chip.type = 'button';
				chip.className = 'perflocale-str-modal__ph-chip';
				chip.setAttribute( 'data-ph', ph );
				chip.setAttribute( 'role', 'listitem' );
				chip.innerHTML = '<span class="perflocale-str-modal__ph-icon" aria-hidden="true"></span>' +
					'<code>' + ph.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ) + '</code>';
				chip.addEventListener( 'click', function () {
					var start = textarea.selectionStart;
					var end = textarea.selectionEnd;
					var val = textarea.value;
					textarea.value = val.substring( 0, start ) + ph + val.substring( end );
					textarea.focus();
					textarea.setSelectionRange( start + ph.length, start + ph.length );
					runValidate();
				} );
				phList.appendChild( chip );
			} );
		}

		// Recompute chip states + status + Apply button warning.
		function runValidate() {
			if ( ! window.perflocaleStrValidate || ! modalOriginal ) {
				return;
			}
			var result = window.perflocaleStrValidate.compare( modalOriginal, textarea.value );
			var missing = [], extra = [];
			phList.querySelectorAll( '.perflocale-str-modal__ph-chip' ).forEach( function ( chip ) {
				var ph = chip.getAttribute( 'data-ph' );
				var state = result.states[ ph ] || 'missing';
				chip.classList.remove( 'is-present', 'is-missing', 'is-extra' );
				chip.classList.add( 'is-' + state );
				if ( state === 'missing' ) missing.push( ph );
				else if ( state === 'extra' ) extra.push( ph );
			} );
			var hasIssue = missing.length > 0 || extra.length > 0;
			applyBtn.classList.toggle( 'has-warning', hasIssue );
			if ( missing.length === 0 && extra.length === 0 ) {
				phStatus.textContent = perflocaleStrI18n.allPresent;
				phStatus.className = 'perflocale-str-modal__ph-status is-clean';
			} else {
				var parts = [];
				if ( missing.length ) { parts.push( perflocaleStrI18n.someMissing.replace( '%s', missing.join( ', ' ) ) ); }
				if ( extra.length ) { parts.push( perflocaleStrI18n.someExtra.replace( '%s', extra.join( ', ' ) ) ); }
				phStatus.textContent = parts.join( ' · ' );
				phStatus.className = 'perflocale-str-modal__ph-status is-dirty';
			}
		}

		function scheduleValidate() {
			if ( validateTimer ) clearTimeout( validateTimer );
			validateTimer = setTimeout( runValidate, 80 );
		}

		var lastOpener = null;
		function openModal( btn ) {
			lastOpener = btn;
			modalOriginal = btn.dataset.original || '';
			var context = btn.dataset.context || '';
			var placeholders = JSON.parse( btn.dataset.placeholders || '[]' );
			var lang = btn.dataset.lang || '';
			var fieldName = btn.dataset.field || '';
			var moHint = btn.dataset.moHint || '';
			targetField = document.querySelector( 'input[name="' + fieldName + '"]' );
			originalEl.textContent = modalOriginal;
			textarea.value = targetField ? targetField.value : '';
			textarea.placeholder = moHint || '';
			langLabel.textContent = lang ? perflocaleStrI18n.langTranslation.replace( '%s', function () { return lang; } ) : perflocaleStrI18n.translation;
			if ( context ) { contextEl.textContent = context; contextWrap.style.display = ''; }
			else { contextWrap.style.display = 'none'; }
			if ( placeholders.length > 0 ) {
				phWrap.style.display = '';
				renderChips( placeholders );
				runValidate();
			} else {
				phWrap.style.display = 'none';
				applyBtn.classList.remove( 'has-warning' );
			}
			modal.style.display = '';
			textarea.focus();
		}
		function closeModal() {
			modal.style.display = 'none';
			targetField = null;
			modalOriginal = '';
			applyBtn.classList.remove( 'has-warning' );
			// Return keyboard focus to the row button that opened the dialog,
			// so keyboard users are not stranded at the top of the page.
			if ( lastOpener ) { lastOpener.focus(); lastOpener = null; }
		}
		function applyModal() {
			if ( applyBtn.classList.contains( 'has-warning' ) ) {
				if ( ! confirm( perflocaleStrI18n.applyConfirm ) ) return;
			}
			if ( targetField ) {
				targetField.value = textarea.value;
				if ( textarea.value ) { targetField.classList.add( 'perflocale-str-input--filled' ); }
				else { targetField.classList.remove( 'perflocale-str-input--filled' ); }
			}
			closeModal();
		}

		textarea.addEventListener( 'input', scheduleValidate );
		document.querySelectorAll( '.perflocale-str-expand-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { openModal( this ); } );
		} );
		document.querySelector( '.perflocale-str-modal__close' ).addEventListener( 'click', closeModal );
		document.querySelector( '.perflocale-str-modal__backdrop' ).addEventListener( 'click', closeModal );
		document.getElementById( 'perflocale-str-modal-cancel' ).addEventListener( 'click', closeModal );
		applyBtn.addEventListener( 'click', applyModal );
		document.addEventListener( 'keydown', function ( e ) {
			if ( modal.style.display === 'none' ) { return; }
			if ( e.key === 'Escape' ) { closeModal(); return; }
			// Focus trap: Tab cycles inside the dialog while it is open
			// (aria-modal promises assistive tech exactly that).
			if ( e.key === 'Tab' ) {
				var f = modal.querySelectorAll( 'button,textarea,input,select,a[href]' );
				if ( ! f.length ) { return; }
				var first = f[ 0 ], last = f[ f.length - 1 ];
				if ( e.shiftKey && document.activeElement === first ) { e.preventDefault(); last.focus(); }
				else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); }
			}
		} );
	}() );

	// Per-row check: red border + warning icon on rows whose value is missing
	// source placeholders, live on keystroke. validate() reads
	// window.perflocaleStrValidate when an event fires.
	( function () {
		var inputs = document.querySelectorAll( '.perflocale-str-input' );
		if ( ! inputs.length ) return;

		// The source is the row's expand-button data-original (one button per
		// language column, all with the same source).
		function getSource( input ) {
			var wrap = input.closest( '.perflocale-str-input-wrap' );
			if ( ! wrap ) return '';
			var btn = wrap.querySelector( '.perflocale-str-expand-btn' );
			return btn ? ( btn.dataset.original || '' ) : '';
		}

		function ensureWarn( wrap ) {
			var icon = wrap.querySelector( '.perflocale-str-input-warn' );
			if ( ! icon ) {
				icon = document.createElement( 'span' );
				icon.className = 'perflocale-str-input-warn';
				icon.textContent = '⚠';
				icon.setAttribute( 'aria-hidden', 'true' );
				wrap.appendChild( icon );
			}
			return icon;
		}

		function validate( input ) {
			if ( ! window.perflocaleStrValidate ) return;
			var src = getSource( input );
			var val = input.value;
			var wrap = input.closest( '.perflocale-str-input-wrap' );
			// An empty translation is never invalid (the translator has not
			// started yet). A source with no placeholders has nothing to check.
			if ( ! src || ! val.trim() ) {
				input.classList.remove( 'perflocale-str-input--invalid' );
				var icon = wrap && wrap.querySelector( '.perflocale-str-input-warn' );
				if ( icon ) icon.style.display = 'none';
				return;
			}
			var srcTokens = window.perflocaleStrValidate.extract( src );
			if ( srcTokens.length === 0 ) {
				input.classList.remove( 'perflocale-str-input--invalid' );
				var icon2 = wrap && wrap.querySelector( '.perflocale-str-input-warn' );
				if ( icon2 ) icon2.style.display = 'none';
				return;
			}
			var result = window.perflocaleStrValidate.compare( src, val );
			var missing = [];
			srcTokens.forEach( function ( t ) {
				if ( result.states[ t ] === 'missing' ) missing.push( t );
			} );
			if ( missing.length > 0 ) {
				input.classList.add( 'perflocale-str-input--invalid' );
				var icon3 = ensureWarn( wrap );
				icon3.style.display = '';
				icon3.title = perflocaleStrI18n.inputMissing.replace( '%s', missing.join( ', ' ) );
			} else {
				input.classList.remove( 'perflocale-str-input--invalid' );
				var icon4 = wrap && wrap.querySelector( '.perflocale-str-input-warn' );
				if ( icon4 ) icon4.style.display = 'none';
			}
		}

		inputs.forEach( function ( input ) {
			input.addEventListener( 'input', function () { validate( input ); } );
		} );

		// Initial sweep on the next tick: flags pre-filled rows that are
		// missing a placeholder.
		setTimeout( function () { inputs.forEach( validate ); }, 0 );

		// Re-validate a row input on any change event.
		document.addEventListener( 'change', function ( ev ) {
			if ( ev.target.matches && ev.target.matches( '.perflocale-str-input' ) ) validate( ev.target );
		} );

		// The modal's Apply writes the row input's value without an event, so
		// sweep the rows after Apply.
		var modalApplyBtn = document.getElementById( 'perflocale-str-modal-apply' );
		if ( modalApplyBtn ) {
			modalApplyBtn.addEventListener( 'click', function () {
				setTimeout( function () { inputs.forEach( validate ); }, 50 );
			} );
		}
	}() );
}() );
