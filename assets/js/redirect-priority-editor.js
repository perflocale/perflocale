/**
 * PerfLocale - Redirect priority editor on the Settings > URL & Routing tab.
 *
 * Drag-and-drop / keyboard reorder for the chip list at `.pl-prio-editor`.
 * The chips reuse the `.pl-fb-chip` styling from the language-fallback
 * editor for visual consistency, but the JS here is a stripped-down
 * sortable: no add/remove (the methods are fixed by which redirect
 * mechanisms are enabled above), no per-row picker, no max-length cap.
 *
 * Hidden `<input name="redirect_priority_order[]">` inside each chip carries
 * the order to the form submit; renumber() renumbers visible position
 * counters after every reorder.
 */
( function () {
	var editor = document.querySelector( '.pl-prio-editor' );

	if ( ! editor ) {
		return;
	}

	function renumber() {
		var chips = editor.querySelectorAll( '.pl-prio-chip' );

		chips.forEach( function ( chip, idx ) {
			var pos = chip.querySelector( '.pl-fb-chip__pos' );

			if ( pos ) {
				pos.textContent = String( idx + 1 );
			}

			var nameEl = chip.querySelector( '.pl-fb-chip__name' );

			if ( nameEl ) {
				var clean = nameEl.textContent.trim();
				chip.setAttribute(
					'aria-label',
					'Position ' + ( idx + 1 ) + ': ' + clean
				);
			}
		} );
	}

	// Drag-and-drop reorder.
	var dragChip = null;

	editor.addEventListener( 'dragstart', function ( e ) {
		var chip = e.target.closest( '.pl-prio-chip' );

		if ( ! chip ) {
			return;
		}

		dragChip = chip;
		chip.classList.add( 'pl-fb-chip--dragging' );

		try {
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData( 'text/plain', chip.dataset.method );
		} catch ( err ) {}
	} );

	editor.addEventListener( 'dragend', function () {
		if ( dragChip ) {
			dragChip.classList.remove( 'pl-fb-chip--dragging' );
		}

		editor.querySelectorAll( '.pl-fb-chip--drop-before, .pl-fb-chip--drop-after' )
			.forEach( function ( c ) {
				c.classList.remove( 'pl-fb-chip--drop-before', 'pl-fb-chip--drop-after' );
			} );

		dragChip = null;
	} );

	editor.addEventListener( 'dragover', function ( e ) {
		if ( ! dragChip ) {
			return;
		}

		e.preventDefault();
		e.dataTransfer.dropEffect = 'move';

		var overChip = e.target.closest( '.pl-prio-chip' );

		editor.querySelectorAll( '.pl-fb-chip--drop-before, .pl-fb-chip--drop-after' )
			.forEach( function ( c ) {
				c.classList.remove( 'pl-fb-chip--drop-before', 'pl-fb-chip--drop-after' );
			} );

		if ( overChip && overChip !== dragChip ) {
			var rect = overChip.getBoundingClientRect();
			var before = ( e.clientX - rect.left ) < rect.width / 2;
			overChip.classList.add( before ? 'pl-fb-chip--drop-before' : 'pl-fb-chip--drop-after' );
		}
	} );

	editor.addEventListener( 'drop', function ( e ) {
		if ( ! dragChip ) {
			return;
		}

		e.preventDefault();
		var overChip = e.target.closest( '.pl-prio-chip' );

		if ( overChip && overChip !== dragChip ) {
			var rect = overChip.getBoundingClientRect();
			var before = ( e.clientX - rect.left ) < rect.width / 2;

			if ( before ) {
				editor.insertBefore( dragChip, overChip );
			} else {
				editor.insertBefore( dragChip, overChip.nextElementSibling );
			}
		}

		renumber();
	} );

	// Keyboard reorder via ArrowLeft / ArrowRight on a focused chip.
	editor.addEventListener( 'keydown', function ( e ) {
		var chip = e.target.closest( '.pl-prio-chip' );

		if ( ! chip || e.target !== chip ) {
			return;
		}

		if ( e.key === 'ArrowLeft' ) {
			var prev = chip.previousElementSibling;

			if ( prev && prev.classList.contains( 'pl-prio-chip' ) ) {
				e.preventDefault();
				editor.insertBefore( chip, prev );
				renumber();
				chip.focus();
			}
		} else if ( e.key === 'ArrowRight' ) {
			var next = chip.nextElementSibling;

			if ( next && next.classList.contains( 'pl-prio-chip' ) ) {
				e.preventDefault();
				editor.insertBefore( next, chip );
				renumber();
				chip.focus();
			}
		}
	} );

	renumber();
}() );
