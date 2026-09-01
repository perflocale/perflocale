/**
 * Bulk MT-translate dispatcher for the Strings admin page.
 *
 * Wires up:
 *   - Select-all + per-row checkboxes (rows currently rendered)
 *   - Target-language chip toggles
 *   - Overwrite-existing toggle
 *   - Three action buttons: ids / filter / all
 *
 * Reads runtime config (REST URL, nonce, target list, i18n) from the
 * `perflocaleStrMt` global set by wp_localize_script in Assets.php.
 *
 * Response shape from /strings/machine-translate:
 *   - async run:  { mode: 'async', job_id: '…', threshold: N, work_size: N }
 *   - inline run: { mode: 'sync',  result: { translated, skipped, failed, … } }
 *
 * On async we hand off to the Jobs admin page so the user gets a live
 * progress bar. On sync we surface a toast and offer a manual reload so
 * the row inputs reflect the new translations.
 */
( function () {
	'use strict';

	var cfg = window.perflocaleStrMt;
	if ( ! cfg || ! cfg.restUrl ) {
		return;
	}

	var toolbar = document.querySelector( '[data-perflocale-mt-toolbar]' );
	if ( ! toolbar ) {
		return;
	}

	var i18n              = cfg.i18n || {};

	// ─── Unsaved-edit protection ──────────────────────────────────────
	// Hand-typed translations live in .perflocale-str-input fields until
	// "Save Translations" posts them. This file's own MT flow reloads or
	// navigates away — silently destroying those edits — and pagination/
	// sort links do the same. defaultValue is the server-rendered value,
	// so value !== defaultValue is exactly "typed but unsaved".
	function hasDirtyEdits() {
		var inputs = document.querySelectorAll( '.perflocale-str-input' );
		for ( var i = 0; i < inputs.length; i++ ) {
			if ( inputs[ i ].value !== inputs[ i ].defaultValue ) {
				return true;
			}
		}
		return false;
	}

	var suppressUnloadGuard = false;

	window.addEventListener( 'beforeunload', function ( ev ) {
		if ( suppressUnloadGuard || ! hasDirtyEdits() ) {
			return;
		}
		ev.preventDefault();
		ev.returnValue = ''; // Required by Chrome for the native prompt.
	} );

	// The GET forms on this screen (search, filter) navigate away and discard
	// the typed edits with no server-side persistence — exactly the loss the
	// pagination links already warn about, so let the guard fire for them.
	// Of the POST forms only the ones that actually LEAVE the page may disarm
	// the guard: Save persists the edits and Import is a deliberate replace.
	// Export streams a .po download and the page stays put, so suppressing on
	// it would disarm the guard for the rest of the page's life — nothing
	// re-arms it, since pageshow only fires on a real load / bfcache restore —
	// and the next pagination click would then discard typed edits silently.
	document.addEventListener( 'submit', function ( ev ) {
		var form = ev.target;

		if ( ! form || ! form.method || form.method.toLowerCase() === 'get' ) {
			return;
		}

		var action = form.querySelector( '[name="perflocale_strings_action"]' );

		if ( action && action.value === 'po_export' ) {
			return;
		}

		suppressUnloadGuard = true;
	}, true );

	// A submit that never completes its navigation (a "Stay" on the prompt, a
	// bfcache restore) must not leave the guard permanently disarmed.
	window.addEventListener( 'pageshow', function () {
		suppressUnloadGuard = false;
	} );
	var statusEl          = toolbar.querySelector( '[data-perflocale-mt-status]' );
	var countEl           = toolbar.querySelector( '[data-perflocale-mt-count]' );
	var btnIds            = toolbar.querySelector( '[data-perflocale-mt-action="ids"]' );
	var btnFilter         = toolbar.querySelector( '[data-perflocale-mt-action="filter"]' );
	var btnAll            = toolbar.querySelector( '[data-perflocale-mt-action="all"]' );
	var overwriteInput    = toolbar.querySelector( '[data-perflocale-mt-overwrite]' );
	var targetInputs      = toolbar.querySelectorAll( '[data-perflocale-mt-target]' );
	var selectAllCb       = document.querySelector( '[data-perflocale-mt-select-all]' );
	var rowCheckboxes     = function () { return document.querySelectorAll( '.perflocale-str-mt-cb' ); };

	// ─── Helpers ──────────────────────────────────────────────────────

	function format( tpl, parts ) {
		return tpl.replace( /%(\d+)\$[sd]/g, function ( _m, n ) {
			var idx = parseInt( n, 10 ) - 1;
			return parts[ idx ] === undefined ? '' : String( parts[ idx ] );
		} );
	}

	function setStatus( text, level ) {
		if ( ! statusEl ) return;
		statusEl.textContent = text || '';
		statusEl.className   = 'perflocale-str-mt-toolbar__status' + ( level ? ' is-' + level : '' );
	}

	function collectSelectedIds() {
		var ids = [];
		rowCheckboxes().forEach( function ( cb ) {
			if ( cb.checked ) {
				var sid = parseInt( cb.getAttribute( 'data-perflocale-string-id' ), 10 );
				if ( sid > 0 ) ids.push( sid );
			}
		} );
		return ids;
	}

	function collectTargetIds() {
		var ids = [];
		targetInputs.forEach( function ( cb ) {
			if ( cb.checked ) {
				var lid = parseInt( cb.value, 10 );
				if ( lid > 0 ) ids.push( lid );
			}
		} );
		return ids;
	}

	function collectTargetNames() {
		var names = [];
		targetInputs.forEach( function ( cb ) {
			if ( cb.checked ) {
				var label = cb.parentNode.querySelector( '.perflocale-str-mt-toolbar__chip-label' );
				if ( label ) names.push( label.textContent.trim() );
			}
		} );
		return names;
	}

	function updateSelectedCount() {
		var n = collectSelectedIds().length;
		if ( countEl ) {
			countEl.textContent = '(' + n + ')';
		}
		// Hide the button entirely when there's nothing to act on, rather
		// than showing it greyed out — the disabled state in the toolbar
		// reads as "broken" to users on first encounter.
		if ( btnIds ) {
			btnIds.hidden = ( n === 0 );
		}

		// Reflect partial vs full selection on the master checkbox.
		if ( selectAllCb ) {
			var rows  = rowCheckboxes();
			var total = rows.length;
			if ( total === 0 ) {
				selectAllCb.checked       = false;
				selectAllCb.indeterminate = false;
			} else if ( n === 0 ) {
				selectAllCb.checked       = false;
				selectAllCb.indeterminate = false;
			} else if ( n === total ) {
				selectAllCb.checked       = true;
				selectAllCb.indeterminate = false;
			} else {
				selectAllCb.checked       = false;
				selectAllCb.indeterminate = true;
			}
		}
	}

	// ─── Wire UI ──────────────────────────────────────────────────────

	if ( selectAllCb ) {
		selectAllCb.addEventListener( 'change', function () {
			var on = selectAllCb.checked;
			rowCheckboxes().forEach( function ( cb ) {
				cb.checked = on;
			} );
			updateSelectedCount();
		} );
	}

	// Event delegation on the table — survives any future re-render.
	document.addEventListener( 'change', function ( e ) {
		if ( e.target && e.target.classList && e.target.classList.contains( 'perflocale-str-mt-cb' ) ) {
			updateSelectedCount();
		}
	} );

	// Initial paint so the count chip is correct on page load (e.g. if
	// the browser pre-filled checkboxes from bfcache).
	updateSelectedCount();

	// ─── Dispatch ─────────────────────────────────────────────────────

	function setBusy( busy ) {
		[ btnIds, btnFilter, btnAll ].forEach( function ( b ) {
			if ( b ) b.disabled = busy;
		} );
		if ( busy ) {
			setStatus( i18n.dispatching || 'Dispatching…', 'busy' );
		} else {
			// Restore hidden state on the IDs button (disabled is a
			// transient busy flag; hidden is the resting state when
			// nothing is selected).
			updateSelectedCount();
		}
	}

	function dispatch( payload ) {
		setBusy( true );
		setStatus( '', '' );

		var body = new FormData();
		body.append( 'mode', payload.mode );

		if ( payload.provider_id ) {
			body.append( 'provider_id', payload.provider_id );
		}

		body.append( 'overwrite', payload.overwrite ? '1' : '0' );

		payload.target_lang_ids.forEach( function ( id ) {
			body.append( 'target_lang_ids[]', String( id ) );
		} );

		if ( payload.mode === 'ids' ) {
			payload.string_ids.forEach( function ( sid ) {
				body.append( 'string_ids[]', String( sid ) );
			} );
		} else if ( payload.mode === 'filter' && payload.filter ) {
			[ 'domain', 'context', 'search', 'search_mode', 'status', 'language_id' ].forEach( function ( k ) {
				if ( payload.filter[ k ] !== undefined && payload.filter[ k ] !== null && payload.filter[ k ] !== '' ) {
					body.append( 'filter[' + k + ']', String( payload.filter[ k ] ) );
				}
			} );
		}

		fetch( cfg.restUrl, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'X-WP-Nonce': cfg.nonce },
			body:        body
		} ).then( function ( res ) {
			return res.json().then( function ( json ) {
				return { ok: res.ok, status: res.status, json: json };
			} );
		} ).then( function ( wrap ) {
			if ( ! wrap.ok ) {
				var msg = ( wrap.json && wrap.json.message ) ? wrap.json.message : ( i18n.genericError || 'Error' );
				setStatus( msg, 'error' );
				setBusy( false );
				return;
			}

			var data = wrap.json || {};

			if ( data.mode === 'async' ) {
				setStatus( i18n.queued || 'Queued.', 'success' );
				// Hand off to Jobs page so the user sees live progress —
				// unless it would destroy typed-but-unsaved translations.
				if ( hasDirtyEdits() ) {
					setStatus( ( i18n.queued || 'Queued.' ) + ' ' + ( i18n.dirtyStay || 'Job started — your unsaved edits were kept; save them, then check the Jobs page.' ), 'success' );
					// The user stays on the page to save; release the busy
					// state so a second batch can be dispatched. setBusy(false)
					// only re-enables the buttons — the status text survives.
					setBusy( false );
					return;
				}
				suppressUnloadGuard = true;
				window.location.href = cfg.jobsUrl;
				return;
			}

			// Sync result.
			var r          = ( data.result && typeof data.result === 'object' ) ? data.result : {};
			var translated = parseInt( r.translated || 0, 10 );
			var skipped    = parseInt( r.skipped || 0, 10 );
			var failed     = parseInt( r.failed || 0, 10 );
			var head       = format( i18n.syncDone || '%1$d translated, %2$d skipped, %3$d failed.', [ translated, skipped, failed ] );
			var reload     = ' ' + ( i18n.syncDoneReload || '' );

			setStatus( head + reload, failed > 0 ? 'warn' : 'success' );
			setBusy( false );

			if ( translated > 0 ) {
				if ( hasDirtyEdits() ) {
					// The reload would wipe typed-but-unsaved translations;
					// the status line already tells the user to reload once
					// they've saved.
					return;
				}
				// Soft reload after a short pause so the user sees the toast.
				window.setTimeout( function () {
					suppressUnloadGuard = true;
					window.location.reload();
				}, 1200 );
			}
		} ).catch( function () {
			setStatus( i18n.genericError || 'Error', 'error' );
			setBusy( false );
		} );
	}

	// ─── Action handlers ──────────────────────────────────────────────

	function handleIdsClick() {
		var targets = collectTargetIds();
		if ( targets.length === 0 ) { setStatus( i18n.pickTargets, 'error' ); return; }

		var ids = collectSelectedIds();
		if ( ids.length === 0 ) { setStatus( i18n.pickStrings, 'error' ); return; }

		var work = ids.length * targets.length;
		if ( work > 5000 ) { setStatus( i18n.maxExceeded, 'error' ); return; }

		var prompt = format( i18n.confirmSelected || '', [ ids.length, collectTargetNames().join( ', ' ) ] );
		if ( ! window.confirm( prompt ) ) { return; }

		dispatch( {
			mode:            'ids',
			string_ids:      ids,
			target_lang_ids: targets,
			overwrite:       overwriteInput && overwriteInput.checked,
			provider_id:     cfg.provider || ''
		} );
	}

	function handleFilterClick() {
		var targets = collectTargetIds();
		if ( targets.length === 0 ) { setStatus( i18n.pickTargets, 'error' ); return; }

		var total = parseInt( toolbar.getAttribute( 'data-perflocale-total' ) || '0', 10 );
		var filterRaw = toolbar.getAttribute( 'data-perflocale-filter' ) || '{}';
		var filter;
		try { filter = JSON.parse( filterRaw ); } catch ( e ) { filter = {}; }

		var prompt = format( i18n.confirmFiltered || '', [ total, collectTargetNames().join( ', ' ) ] );
		if ( ! window.confirm( prompt ) ) { return; }

		dispatch( {
			mode:            'filter',
			filter:          filter,
			target_lang_ids: targets,
			overwrite:       overwriteInput && overwriteInput.checked,
			provider_id:     cfg.provider || ''
		} );
	}

	function handleAllClick() {
		var targets = collectTargetIds();
		if ( targets.length === 0 ) { setStatus( i18n.pickTargets, 'error' ); return; }

		// Whole-table count, not the filtered subset — Translate-all
		// ignores any active filter on purpose.
		var total  = parseInt( toolbar.getAttribute( 'data-perflocale-grand-total' ) || toolbar.getAttribute( 'data-perflocale-total' ) || '0', 10 );
		var prompt = format( i18n.confirmAll || '', [ total, collectTargetNames().join( ', ' ) ] );
		if ( ! window.confirm( prompt ) ) { return; }

		dispatch( {
			mode:            'all',
			target_lang_ids: targets,
			overwrite:       overwriteInput && overwriteInput.checked,
			provider_id:     cfg.provider || ''
		} );
	}

	if ( btnIds )    btnIds.addEventListener( 'click', handleIdsClick );
	if ( btnFilter ) btnFilter.addEventListener( 'click', handleFilterClick );
	if ( btnAll )    btnAll.addEventListener( 'click', handleAllClick );
} )();
