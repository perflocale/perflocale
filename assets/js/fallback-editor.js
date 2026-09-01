/**
 * PerfLocale - fallback-chain editor on the Settings > URL & Routing tab.
 *
 * Reads translated labels from window.perflocaleFallbackEditor (seeded by
 * wp_localize_script) and wires up the chip-based per-language fallback
 * picker: drag/drop, keyboard reorder, add/remove, position renumbering.
 */
( function () {
	var editor = document.querySelector( '.pl-fb-editor' );
	if ( ! editor ) { return; }

	var MAX = parseInt( editor.dataset.max, 10 ) || 4;
	var L = ( window.perflocaleFallbackEditor && window.perflocaleFallbackEditor.labels ) || {};
	var LABELS = {
		removePrefix: L.removePrefix || 'Remove',
		removeSuffix: L.removeSuffix || 'fallback',
		dragHint: L.dragHint || 'Draggable fallback',
		positionTpl: L.positionTpl || 'Position %1$d: %2$s'
	};

	function buildAriaLabel( position, langName ) {
		return LABELS.positionTpl
			.replace( '%1$d', String( position ) )
			.replace( '%2$s', langName );
	}

	function renumber( row ) {
		var body = row.querySelector( '.pl-fb-row__body' );
		var chips = body.querySelectorAll( '.pl-fb-chip' );
		chips.forEach( function ( chip, idx ) {
			var pos = chip.querySelector( '.pl-fb-chip__pos' );
			if ( pos ) { pos.textContent = String( idx + 1 ); }
			var nameEl = chip.querySelector( '.pl-fb-chip__name' );
			if ( nameEl ) {
				var clean = nameEl.textContent.replace( /^\S+\s+/, '' ).trim() || nameEl.textContent.trim();
				chip.setAttribute( 'aria-label', buildAriaLabel( idx + 1, clean ) );
			}
		} );
		if ( chips.length >= MAX ) { body.classList.add( 'pl-fb-row__body--full' ); }
		else { body.classList.remove( 'pl-fb-row__body--full' ); }
	}

	function buildChip( rowSlug, fbSlug, flag, name ) {
		var el = document.createElement( 'div' );
		el.className = 'pl-fb-chip';
		el.dataset.slug = fbSlug;
		el.draggable = true;
		el.tabIndex = 0;
		el.setAttribute( 'aria-roledescription', LABELS.dragHint );
		var removeLabel = LABELS.removePrefix + ' ' + name + ' ' + LABELS.removeSuffix;
		el.innerHTML =
			'<span class="pl-fb-chip__grip" aria-hidden="true">⋮⋮</span>' +
			'<span class="pl-fb-chip__pos"></span>' +
			'<span class="pl-fb-chip__name"></span>' +
			'<button type="button" class="pl-fb-chip__remove">×</button>' +
			'<input type="hidden" name="language_fallbacks[' + rowSlug + '][]">';
		el.querySelector( '.pl-fb-chip__name' ).textContent = flag + ' ' + name;
		// Set the button label via the DOM API (auto-escaped) rather than
		// interpolating the language name into the innerHTML attribute string.
		var removeBtn = el.querySelector( '.pl-fb-chip__remove' );
		removeBtn.setAttribute( 'aria-label', removeLabel );
		removeBtn.setAttribute( 'title', removeLabel );
		el.querySelector( 'input[type="hidden"]' ).value = fbSlug;
		return el;
	}

	editor.addEventListener( 'change', function ( e ) {
		var picker = e.target.closest( '.pl-fb-row__picker' );
		if ( ! picker ) { return; }
		var slug = picker.value;
		if ( ! slug ) { return; }

		var row = picker.closest( '.pl-fb-row' );
		var body = row.querySelector( '.pl-fb-row__body' );
		var rowSlug = row.dataset.slug;

		if ( body.querySelectorAll( '.pl-fb-chip' ).length >= MAX ) {
			picker.value = '';
			return;
		}

		var opt = picker.querySelector( 'option[value="' + CSS.escape( slug ) + '"]' );
		if ( ! opt ) { picker.value = ''; return; }

		body.insertBefore( buildChip( rowSlug, slug, opt.dataset.flag, opt.dataset.name ), picker );
		opt.disabled = true;
		opt.hidden = true;
		picker.value = '';
		renumber( row );
	} );

	editor.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.pl-fb-chip__remove' );
		if ( ! btn ) { return; }
		var chip = btn.closest( '.pl-fb-chip' );
		var row = chip.closest( '.pl-fb-row' );
		var slug = chip.dataset.slug;
		var picker = row.querySelector( '.pl-fb-row__picker' );
		if ( picker ) {
			var opt = picker.querySelector( 'option[value="' + CSS.escape( slug ) + '"]' );
			if ( opt ) { opt.disabled = false; opt.hidden = false; }
		}
		chip.remove();
		renumber( row );
	} );

	var dragChip = null;
	var dragRow = null;

	editor.addEventListener( 'dragstart', function ( e ) {
		var chip = e.target.closest( '.pl-fb-chip' );
		if ( ! chip ) { return; }
		if ( e.target.closest( '.pl-fb-chip__remove' ) ) { e.preventDefault(); return; }
		dragChip = chip;
		dragRow = chip.closest( '.pl-fb-row' );
		chip.classList.add( 'pl-fb-chip--dragging' );
		try {
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData( 'text/plain', chip.dataset.slug );
		} catch ( err ) {}
		dragRow.querySelector( '.pl-fb-row__body' ).classList.add( 'pl-fb-row__body--active' );
	} );

	editor.addEventListener( 'dragend', function () {
		if ( dragChip ) { dragChip.classList.remove( 'pl-fb-chip--dragging' ); }
		if ( dragRow ) { dragRow.querySelector( '.pl-fb-row__body' ).classList.remove( 'pl-fb-row__body--active' ); }
		editor.querySelectorAll( '.pl-fb-chip--drop-before, .pl-fb-chip--drop-after' )
			.forEach( function ( c ) { c.classList.remove( 'pl-fb-chip--drop-before', 'pl-fb-chip--drop-after' ); } );
		dragChip = null;
		dragRow = null;
	} );

	editor.addEventListener( 'dragover', function ( e ) {
		if ( ! dragChip ) { return; }
		var body = e.target.closest( '.pl-fb-row__body' );
		if ( ! body ) { return; }
		if ( body !== dragRow.querySelector( '.pl-fb-row__body' ) ) { return; }

		e.preventDefault();
		e.dataTransfer.dropEffect = 'move';

		var overChip = e.target.closest( '.pl-fb-chip' );
		body.querySelectorAll( '.pl-fb-chip--drop-before, .pl-fb-chip--drop-after' )
			.forEach( function ( c ) { c.classList.remove( 'pl-fb-chip--drop-before', 'pl-fb-chip--drop-after' ); } );

		if ( overChip && overChip !== dragChip ) {
			var rect = overChip.getBoundingClientRect();
			var before = ( e.clientX - rect.left ) < rect.width / 2;
			overChip.classList.add( before ? 'pl-fb-chip--drop-before' : 'pl-fb-chip--drop-after' );
		}
	} );

	editor.addEventListener( 'drop', function ( e ) {
		if ( ! dragChip ) { return; }
		var body = e.target.closest( '.pl-fb-row__body' );
		if ( ! body || body !== dragRow.querySelector( '.pl-fb-row__body' ) ) { return; }

		e.preventDefault();
		var overChip = e.target.closest( '.pl-fb-chip' );
		var picker = body.querySelector( '.pl-fb-row__picker' );

		if ( overChip && overChip !== dragChip ) {
			var rect = overChip.getBoundingClientRect();
			var before = ( e.clientX - rect.left ) < rect.width / 2;
			if ( before ) {
				body.insertBefore( dragChip, overChip );
			} else {
				body.insertBefore( dragChip, overChip.nextElementSibling );
			}
		} else if ( ! overChip ) {
			body.insertBefore( dragChip, picker );
		}

		renumber( dragRow );
	} );

	function prevChip( chip ) {
		var n = chip.previousElementSibling;
		while ( n && ! n.classList.contains( 'pl-fb-chip' ) ) { n = n.previousElementSibling; }
		return n;
	}
	function nextChip( chip ) {
		var n = chip.nextElementSibling;
		while ( n && ! n.classList.contains( 'pl-fb-chip' ) ) { n = n.nextElementSibling; }
		return n;
	}

	editor.addEventListener( 'keydown', function ( e ) {
		var chip = e.target.closest( '.pl-fb-chip' );
		if ( ! chip || e.target !== chip ) { return; }
		var row = chip.closest( '.pl-fb-row' );
		if ( e.key === 'ArrowLeft' ) {
			var prev = prevChip( chip );
			if ( ! prev ) { return; }
			e.preventDefault();
			chip.parentNode.insertBefore( chip, prev );
			renumber( row );
			chip.focus();
		} else if ( e.key === 'ArrowRight' ) {
			var next = nextChip( chip );
			if ( ! next ) { return; }
			e.preventDefault();
			chip.parentNode.insertBefore( next, chip );
			renumber( row );
			chip.focus();
		} else if ( e.key === 'Delete' || e.key === 'Backspace' ) {
			e.preventDefault();
			chip.querySelector( '.pl-fb-chip__remove' ).click();
		}
	} );

	editor.querySelectorAll( '.pl-fb-row' ).forEach( renumber );
}() );
