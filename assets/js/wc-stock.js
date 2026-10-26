/**
 * PerfLocale - "Stock across languages" block of the WooCommerce add-on
 * settings (StockAlignScreen).
 *
 * "Check stock" asks admin-ajax (perflocale_wc_stock_check) for the copy
 * groups whose stock differs; the answer is the table to show, or a queued
 * job whose status the block asks for every 3 seconds
 * (perflocale_wc_stock_status). "Align" aligns one listed group,
 * "Align all to the default language" every drifting group after a
 * confirmation (perflocale_wc_stock_align). The stock values the user saw
 * go along as `expected`, a precondition only: the server reads every value
 * it writes from the database.
 *
 * The nonce, the ajax URL and the texts come from the block's
 * data-perflocale-wc-stock attribute; counts and messages are written by the
 * server. Everything is built with textContent (no HTML from a response).
 */
( function () {
	'use strict';

	var block = document.querySelector( '[data-perflocale-wc-stock]' );

	if ( ! block ) {
		return;
	}

	var data;

	try {
		data = JSON.parse( block.getAttribute( 'data-perflocale-wc-stock' ) || '{}' );
	} catch ( e ) {
		return;
	}

	var t = data.i18n || {};
	var checkButton = block.querySelector( '.perflocale-wc-stock__check' );
	var progress = block.querySelector( '.perflocale-wc-stock__progress' );
	var status = block.querySelector( '.perflocale-wc-stock__status' );
	var result = block.querySelector( '.perflocale-wc-stock__result' );
	var confirmBox = block.querySelector( '.perflocale-wc-stock__confirm' );
	var confirmText = block.querySelector( '.perflocale-wc-stock__confirm-text' );
	var confirmYes = block.querySelector( '.perflocale-wc-stock__confirm-yes' );
	var confirmNo = block.querySelector( '.perflocale-wc-stock__confirm-no' );
	var left = block.querySelector( '.perflocale-wc-stock__left' );
	var alignAllButton = null;
	var lastCheck = null;
	var busy = false;

	function el( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( typeof text === 'string' ) {
			node.textContent = text;
		}

		return node;
	}

	function say( message ) {
		status.textContent = message || '';

		if ( message && window.wp && wp.a11y && wp.a11y.speak ) {
			wp.a11y.speak( message, 'polite' );
		}
	}

	function setBusy( on, label ) {
		busy = on;
		checkButton.disabled = on;

		if ( alignAllButton ) {
			alignAllButton.disabled = on;
		}

		result.querySelectorAll( '.perflocale-wc-stock__align' ).forEach( function ( button ) {
			button.disabled = on;
		} );

		progress.hidden = ! on;

		if ( on ) {
			progress.removeAttribute( 'value' );
			progress.setAttribute( 'aria-label', label || '' );
		}
	}

	function post( action, fields ) {
		var body = new URLSearchParams();

		body.append( 'action', action );
		body.append( '_nonce', data.nonce );

		Object.keys( fields || {} ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		return fetch( data.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			// A nonce that no longer verifies answers -1 (403).
			return response.text().then( function ( text ) {
				if ( text === '-1' || text === '0' ) {
					throw new Error( t.expired );
				}

				var json;

				try {
					json = JSON.parse( text );
				} catch ( e ) {
					throw new Error( t.failed );
				}

				if ( ! json || ! json.success ) {
					throw new Error( json && json.data && json.data.message ? json.data.message : t.failed );
				}

				return json.data;
			} );
		}, function () {
			throw new Error( t.failed );
		} );
	}

	// A queued job: its status every 3 seconds until it ends.
	function poll( job, kind ) {
		return new Promise( function ( resolve, reject ) {
			function next() {
				post( 'perflocale_wc_stock_status', { job: job } ).then( function ( answer ) {
					if ( answer.status === 'complete' ) {
						resolve( answer.view );
						return;
					}

					if ( answer.status === 'failed' || answer.status === 'canceled' ) {
						reject( new Error( answer.message || t.failed ) );
						return;
					}

					progress.value = answer.progress || 0;
					progress.setAttribute( 'aria-valuetext', String( answer.progress || 0 ) + '%' );
					window.setTimeout( next, 3000 );
				}, reject );
			}

			window.setTimeout( next, 3000 );
		} ).then( function ( view ) {
			return { view: view, kind: kind };
		} );
	}

	function run( action, fields, kind, label ) {
		setBusy( true, label );
		say( label );

		return post( action, fields ).then( function ( answer ) {
			if ( answer.job ) {
				say( answer.message );
				return poll( answer.job, kind );
			}

			return { view: answer.view, kind: kind };
		} ).then( function ( done ) {
			setBusy( false );
			return done.view;
		}, function ( error ) {
			setBusy( false );
			say( error.message );
			throw error;
		} );
	}

	function renderCheck( view ) {
		lastCheck = view;
		result.textContent = '';
		left.textContent = '';
		alignAllButton = null;
		say( view.summary );

		if ( ! view.groups || ! view.groups.length ) {
			return;
		}

		result.appendChild( el( 'p', '', view.summary ) );

		if ( view.more ) {
			result.appendChild( el( 'p', 'description', view.more ) );
		}

		var wrap = el( 'div', 'perflocale-wc-stock__table-wrap' );
		var table = el( 'table', 'widefat striped perflocale-wc-stock__table' );
		var caption = el( 'caption', 'screen-reader-text', t.caption );
		var head = el( 'thead' );
		var headRow = el( 'tr' );
		var body = el( 'tbody' );

		table.appendChild( caption );

		var productHead = el( 'th', '', t.product );

		productHead.setAttribute( 'scope', 'col' );
		headRow.appendChild( productHead );

		view.columns.forEach( function ( column ) {
			var th = el( 'th', column.reference ? 'perflocale-wc-stock__reference' : '', column.label );

			th.setAttribute( 'scope', 'col' );
			headRow.appendChild( th );
		} );

		headRow.appendChild( el( 'td' ) );
		head.appendChild( headRow );
		table.appendChild( head );

		view.groups.forEach( function ( group ) {
			var row = el( 'tr' );
			var name = el( 'th' );

			name.setAttribute( 'scope', 'row' );

			if ( group.link ) {
				var link = el( 'a', '', group.name );

				link.href = group.link;
				name.appendChild( link );
			} else {
				name.textContent = group.name;
			}

			row.appendChild( name );

			group.cells.forEach( function ( cell ) {
				var td = el( 'td', cell.differs ? 'perflocale-wc-stock__differs' : '' );

				td.appendChild( document.createTextNode( cell.empty ? '-' : cell.stock ) );

				if ( cell.differs || cell.empty ) {
					td.appendChild( document.createTextNode( ' ' ) );
					td.appendChild( el( 'span', 'screen-reader-text', cell.differs ? t.differs : t.notCompared ) );
				}

				row.appendChild( td );
			} );

			var action = el( 'td', 'perflocale-wc-stock__row-action' );

			if ( group.alignable ) {
				var button = el( 'button', 'button perflocale-wc-stock__align', t.align );

				button.type = 'button';
				button.setAttribute( 'aria-label', group.alignLabel );
				button.addEventListener( 'click', function () {
					alignOne( group, action, button );
				} );
				action.appendChild( button );
			} else {
				action.textContent = group.reason;
			}

			row.appendChild( action );
			body.appendChild( row );
		} );

		table.appendChild( body );
		wrap.appendChild( table );
		result.appendChild( wrap );

		if ( view.canAlign ) {
			var actions = el( 'p', 'perflocale-wc-stock__actions' );

			alignAllButton = el( 'button', 'button button-primary', t.alignAll );
			alignAllButton.type = 'button';
			alignAllButton.addEventListener( 'click', openConfirm );
			actions.appendChild( alignAllButton );
			result.appendChild( actions );
		}
	}

	function renderAlign( view, target ) {
		say( view.left ? view.summary + ' ' + view.left : view.summary );

		var box = target || left;

		box.textContent = '';
		box.appendChild( el( 'p', '', view.summary ) );

		if ( view.left ) {
			box.appendChild( el( 'p', '', view.left ) );

			var list = el( 'ul', 'perflocale-wc-stock__left-list' );

			view.rows.forEach( function ( row ) {
				list.appendChild( el( 'li', '', row.name + ': ' + row.reason ) );
			} );

			box.appendChild( list );
		}
	}

	function alignOne( group, cell, button ) {
		if ( busy ) {
			return;
		}

		run(
			'perflocale_wc_stock_align',
			{
				scope: 'group',
				group: String( group.group ),
				expected: JSON.stringify( group.expected )
			},
			'align',
			t.aligning
		).then( function ( view ) {
			renderAlign( view, cell );
		}, function () {
			button.focus();
		} );
	}

	function openConfirm() {
		if ( busy || ! lastCheck ) {
			return;
		}

		confirmText.textContent = lastCheck.confirm;
		confirmBox.hidden = false;
		confirmBox.focus();
	}

	function closeConfirm( focusBack ) {
		confirmBox.hidden = true;

		if ( focusBack && alignAllButton ) {
			alignAllButton.focus();
		}
	}

	function alignAll() {
		var expected = {};

		closeConfirm( false );

		( lastCheck && lastCheck.groups ? lastCheck.groups : [] ).forEach( function ( group ) {
			if ( group.alignable ) {
				Object.keys( group.expected ).forEach( function ( anchor ) {
					expected[ anchor ] = group.expected[ anchor ];
				} );
			}
		} );

		run(
			'perflocale_wc_stock_align',
			{
				scope: 'all',
				expected: JSON.stringify( expected )
			},
			'align',
			t.aligning
		).then( function ( view ) {
			renderAlign( view, null );
		}, function () {} );
	}

	checkButton.addEventListener( 'click', function () {
		if ( busy ) {
			return;
		}

		closeConfirm( false );
		run( 'perflocale_wc_stock_check', {}, 'check', t.checking ).then( renderCheck, function () {} );
	} );

	confirmYes.addEventListener( 'click', alignAll );
	confirmNo.addEventListener( 'click', function () {
		closeConfirm( true );
	} );
	confirmBox.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' ) {
			closeConfirm( true );
		}
	} );
}() );
