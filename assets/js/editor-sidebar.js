/**
 * PerfLocale - Gutenberg Editor Sidebar Panel
 *
 * Renders a translation panel directly in the document sidebar (Post tab)
 * using PluginDocumentSettingPanel for a native, integrated feel.
 *
 * @package PerfLocale
 */

/* global wp, perflocaleEditor */
( function() {
	'use strict';

	var registerPlugin = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var ToggleControl = wp.components.ToggleControl;

	var config = perflocaleEditor || {};
	var i18n = config.i18n || {};

	// ---------------------------------------------------------------
	// JavaScript hooks (wp.hooks). Language, PostRef and EditorContext
	// are described in block-toolbar.js.
	// ---------------------------------------------------------------

	var hookWarned = {};

	// The rows of the last accepted Translations response, unfiltered.
	var lastRows = [];

	// With SCRIPT_DEBUG: one console warning per hook and page load.
	function hookWarn( name, why ) {
		if ( config.debug && ! hookWarned[ name ] && window.console ) {
			hookWarned[ name ] = true;
			window.console.warn( 'PerfLocale: ' + name + ': ' + why );
		}
	}

	// wp.hooks.applyFilters on a copy of `value` (plain data), in a
	// try/catch: a callback that throws, or a result `accept` refuses, gives
	// the unfiltered value, whatever the callback changed in its copy.
	function applyFilters( name, value, context, accept ) {
		var out;

		try {
			out = wp.hooks.applyFilters( name, JSON.parse( JSON.stringify( value ) ), context );
		} catch ( e ) {
			hookWarn( name, 'a callback threw; the unfiltered value is used.' );
			return value;
		}

		if ( accept( out ) ) {
			return out;
		}

		hookWarn( name, 'a callback returned a value the hook does not accept; the unfiltered value is used.' );
		return value;
	}

	// wp.hooks.doAction in a try/catch: a callback that throws does not stop the panel.
	function doAction( name, context ) {
		try {
			wp.hooks.doAction( name, context );
		} catch ( e ) {
			hookWarn( name, 'a callback threw.' );
		}
	}

	// The Language object of a Translations row.
	function rowLanguage( row ) {
		return {
			slug:       String( row.slug ),
			locale:     String( row.locale || '' ),
			name:       String( row.name || '' ),
			nativeName: String( row.native_name || row.name || '' ),
			isDefault:  !! row.is_default
		};
	}

	function findRow( rows, test ) {
		for ( var i = 0; i < rows.length; i++ ) {
			if ( rows[ i ] && test( rows[ i ] ) ) {
				return rows[ i ];
			}
		}

		return null;
	}

	function rowLanguageBySlug( rows, slug ) {
		var row = findRow( rows, function ( r ) { return r.slug === slug; } );

		return row ? rowLanguage( row ) : null;
	}

	/**
	 * The editor context plus `extra`. The rows are permission-scoped: a
	 * sibling the user may not view has no id, so it is never sourcePost.
	 *
	 * @returns {EditorContext}
	 */
	function editorContext( rows, extra ) {
		var current = findRow( rows, function ( r ) { return r.is_current; } );
		var source  = findRow( rows, function ( r ) { return r.is_default && ! r.is_current && r.post_id; } );

		return Object.assign( {
			version:    1,
			post:       {
				id:       Number( config.postId ) || 0,
				type:     String( config.postType || '' ),
				language: current ? rowLanguage( current ) : null
			},
			sourcePost: ( current && ! current.is_default && source )
				? { id: Number( source.post_id ), language: rowLanguage( source ) }
				: null
		}, extra );
	}

	// A row of the perflocale.editorSidebar.rows filter.
	function hookRow( row ) {
		return {
			language:       rowLanguage( row ),
			isCurrent:      !! row.is_current,
			hasTranslation: !! row.has_translation,
			translation:    row.post_id
				? { id: Number( row.post_id ), status: String( row.post_status || '' ), editUrl: row.edit_url || null }
				: null
		};
	}

	// The server rows to show, in the order the filter returned: each row
	// matched by language.slug at most once, unknown or malformed entries
	// dropped, the current-language row kept at its own position.
	function filterRows( rows ) {
		/**
		 * @hook perflocale.editorSidebar.rows
		 * @kind filter
		 * @since 1.0.7.4
		 * @cardinality per-response
		 * @fires Once per accepted list response of the Translations panel, before it is stored; the result is kept, not applied again on render.
		 * @param {Array<{language: Language, isCurrent: boolean, hasTranslation: boolean, translation: ?{id: number, status: string, editUrl: ?string}}>} rows One row per language; translation is null when there is none or the user may not view it.
		 * @returns {Array<Object>} Rows of the input (matched by language.slug, each at most once): hide or reorder only. Unknown or malformed entries are dropped and the current-language row is always kept; anything but an array leaves the rows unfiltered.
		 * @context {EditorContext} context
		 * @example wp.hooks.addFilter( 'perflocale.editorSidebar.rows', 'acme/hide-fr', function ( rows ) {
		 *     return rows.filter( function ( row ) { return row.language.slug !== 'fr'; } );
		 * } );
		 */
		var out = applyFilters( 'perflocale.editorSidebar.rows', rows.map( hookRow ), editorContext( rows, {} ), Array.isArray );
		var at = {};
		var used = {};
		var result = [];
		var dropped = false;

		rows.forEach( function ( row, i ) { at[ row.slug ] = i; } );

		out.forEach( function ( r ) {
			var slug = r && r.language && typeof r.language.slug === 'string' ? r.language.slug : null;

			if ( slug === null || ! Object.prototype.hasOwnProperty.call( at, slug ) || used[ slug ] ) {
				dropped = true;
				return;
			}

			used[ slug ] = true;
			result.push( rows[ at[ slug ] ] );
		} );

		if ( dropped ) {
			hookWarn( 'perflocale.editorSidebar.rows', 'unknown, repeated or malformed rows were dropped.' );
		}

		rows.forEach( function ( row, i ) {
			if ( row.is_current && ! used[ row.slug ] ) {
				result.splice( Math.min( i, result.length ), 0, row );
			}
		} );

		return result;
	}

	// Why machine translation was not saved, by the Create action for this
	// translation or by auto-translate on publish for this post: the server
	// hands the text over once, when the editor opens the post. Shown as
	// plain text.
	if ( config.mtCreateNotice ) {
		wp.data.dispatch( 'core/notices' ).createNotice(
			'warning',
			String( config.mtCreateNotice ),
			{ id: 'perflocale-mt-create', isDismissible: true }
		);
	}

	function PerfLocalePanel() {
		// Per-post sync opt-out flag, read/written through the registered
		// REST meta (all hooks at top level - React rules of hooks).
		var postMeta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' );
		}, [] );
		var editPost = useDispatch( 'core/editor' ).editPost;

		var stateData = useState( [] );
		var languages = stateData[0];
		var setLanguages = stateData[1];

		var unassignedState = useState( false );
		var isUnassigned = unassignedState[0];
		var setIsUnassigned = unassignedState[1];

		var loadingState = useState( true );
		var isLoading = loadingState[0];
		var setIsLoading = loadingState[1];

		var busyState = useState( null );
		var busySlug = busyState[0];
		var setBusySlug = busyState[1];

		// In-flight request token shared across every fetchTranslations()
		// call site (mount, post-assign, post-publish, etc.). When a new
		// call starts, the prior request's response is discarded - that
		// way a slow in-flight request can't overwrite the state with
		// stale data after a faster follow-up has already settled. The
		// alternative AbortController approach isn't portable across all
		// `apiFetch` middleware chains; an "is this still the latest?"
		// token works everywhere. Stored in a ref so it persists across
		// re-renders - a plain `var` would reset to 0 every render and the
		// stale-response guard would never actually discard an older fetch.
		var fetchTokenRef = useRef( 0 );

		function fetchTranslations() {
			var myToken = ++fetchTokenRef.current;

			setIsLoading( true );
			apiFetch( {
				path: 'perflocale/v1/translations/post/' + config.postId
			} ).then( function( response ) {
				if ( myToken !== fetchTokenRef.current ) {
					// A newer call started after we did; let that one
					// own the final state. We still bail out of our
					// own loading state so the spinner doesn't stick
					// if our request was the slow one.
					return;
				}
				if ( response && response.languages ) {
					lastRows = response.languages;
					setLanguages( filterRows( response.languages ) );
					setIsUnassigned( !! response.is_unassigned );
				}
				setIsLoading( false );
			} ).catch( function() {
				if ( myToken !== fetchTokenRef.current ) {
					return;
				}
				setIsLoading( false );
			} );
		}

		function handleAssignLanguage( langSlug ) {
			setBusySlug( langSlug );
			apiFetch( {
				path: 'perflocale/v1/translations/post/' + config.postId + '/language',
				method: 'POST',
				data: { slug: langSlug }
			} ).then( function() {
				setBusySlug( null );

				/**
				 * @hook perflocale.translation.languageAssigned
				 * @kind action
				 * @since 1.0.7.4
				 * @cardinality transition
				 * @fires After Set as <language> succeeded (offered only on a post with no language).
				 * @context {EditorContext} context
				 * @property {?Language} context.language The language the post now has.
				 * @example wp.hooks.addAction( 'perflocale.translation.languageAssigned', 'acme/log', function ( ctx ) {
				 *     console.log( ctx.post.id, ctx.language && ctx.language.slug );
				 * } );
				 */
				doAction( 'perflocale.translation.languageAssigned', editorContext( lastRows, {
					language: rowLanguageBySlug( lastRows, langSlug )
				} ) );

				fetchTranslations();
			} ).catch( function() {
				setBusySlug( null );
				// A 409 means the panel's view is stale (the language was
				// assigned server-side, e.g. on save). Re-fetch so the panel
				// converges on reality instead of keeping dead buttons.
				fetchTranslations();
			} );
		}

		useEffect( function() {
			if ( config.postId ) {
				fetchTranslations();
			}

			// Watch for post status changes (e.g., publish) to refresh the panel.
			if ( wp.data && wp.data.subscribe ) {
				var lastStatus = '';
				var unsubscribe = wp.data.subscribe( function() {
					var post = wp.data.select( 'core/editor' ).getCurrentPost();
					if ( post && post.status && post.status !== lastStatus ) {
						var prev = lastStatus;
						lastStatus = post.status;
						// Refresh after a publish transition.
						if ( prev && post.status === 'publish' && prev !== 'publish' ) {
							setTimeout( fetchTranslations, 500 );
						}
					}
				} );

				return unsubscribe;
			}
		}, [] );

		function handleCreate( langSlug ) {
			var editor = wp.data && wp.data.select( 'core/editor' );

			if ( editor && editor.getEditedPostAttribute( 'status' ) === 'auto-draft' ) {
				wp.data.dispatch( 'core/notices' ).createNotice(
					'warning',
					__( 'Save the post before creating translations.', 'perflocale' ),
					{ type: 'snackbar', isDismissible: true }
				);
				return;
			}

			var targetLanguage = rowLanguageBySlug( lastRows, langSlug );

			/**
			 * @hook perflocale.translation.beforeCreate
			 * @kind filter
			 * @since 1.0.7.4
			 * @cardinality transition
			 * @fires + Create clicked in the Translations panel, after the unsaved-post check, before the spinner and the request.
			 * @param {{copyContent: boolean}} value { copyContent: true }: the new translation starts as a copy of the original.
			 * @returns {false|{copyContent: boolean}} false cancels: no spinner, no request, no notice. { copyContent: false } creates an empty translation. Anything else is ignored.
			 * @context {EditorContext} context
			 * @property {?Language} context.targetLanguage The language of the translation to create.
			 * @example wp.hooks.addFilter( 'perflocale.translation.beforeCreate', 'acme/empty-de', function ( options, ctx ) {
			 *     return ctx.targetLanguage && ctx.targetLanguage.slug === 'de' ? { copyContent: false } : options;
			 * } );
			 */
			var create = applyFilters( 'perflocale.translation.beforeCreate', { copyContent: true }, editorContext( lastRows, {
				targetLanguage: targetLanguage
			} ), function ( v ) {
				return v === false || ( !! v && typeof v === 'object' && typeof v.copyContent === 'boolean' );
			} );

			if ( create === false ) {
				return;
			}

			setBusySlug( langSlug );
			apiFetch( {
				path: 'perflocale/v1/translations/post/' + config.postId,
				method: 'POST',
				data: { target_lang: langSlug, copy_content: create.copyContent }
			} ).then( function( response ) {
				setBusySlug( null );
				if ( response && response.edit_url ) {
					// A window.open() here runs in the apiFetch continuation,
					// past the click's user-activation window - Safari blocks
					// the popup. A snackbar action opens the tab from a fresh
					// user click, which is never blocked.
					wp.data.dispatch( 'core/notices' ).createNotice(
						'success',
						__( 'Translation created.', 'perflocale' ),
						{
							type: 'snackbar',
							isDismissible: true,
							actions: [ {
								label: __( 'Open translation', 'perflocale' ),
								onClick: function() { window.open( response.edit_url, '_blank' ); }
							} ]
						}
					);
				}

				/**
				 * @hook perflocale.translation.created
				 * @kind action
				 * @since 1.0.7.4
				 * @cardinality transition
				 * @fires After + Create succeeded.
				 * @context {EditorContext} context
				 * @property {?Language} context.targetLanguage The new translation's language.
				 * @property {{id: number}} context.translation The new translation post.
				 * @property {?string} context.editUrl Its edit link, or null.
				 * @example wp.hooks.addAction( 'perflocale.translation.created', 'acme/log', function ( ctx ) {
				 *     console.log( 'created', ctx.translation.id );
				 * } );
				 */
				doAction( 'perflocale.translation.created', editorContext( lastRows, {
					targetLanguage: targetLanguage,
					translation:    { id: Number( response && response.post_id ) || 0 },
					editUrl:        ( response && response.edit_url ) || null
				} ) );

				fetchTranslations();
			} ).catch( function( err ) {
				setBusySlug( null );
				// Say why: a refusal (for example a translation copied from an
				// original this user may not edit) otherwise just stops the
				// spinner and leaves the row unchanged.
				wp.data.dispatch( 'core/notices' ).createNotice(
					'error',
					( err && err.message ) || __( 'Failed to create translation.', 'perflocale' ),
					{ type: 'snackbar', isDismissible: true }
				);

				/**
				 * @hook perflocale.translation.createFailed
				 * @kind action
				 * @since 1.0.7.4
				 * @cardinality transition
				 * @fires After + Create failed.
				 * @context {EditorContext} context
				 * @property {?Language} context.targetLanguage The language that was not created.
				 * @property {{code: string, message: string, status: ?number}} context.error code ('' if none), the message the notice shows, HTTP status (null without a response).
				 * @example wp.hooks.addAction( 'perflocale.translation.createFailed', 'acme/log', function ( ctx ) {
				 *     console.warn( ctx.error.code, ctx.error.status );
				 * } );
				 */
				doAction( 'perflocale.translation.createFailed', editorContext( lastRows, {
					targetLanguage: targetLanguage,
					error:          {
						code:    ( err && typeof err.code === 'string' ) ? err.code : '',
						message: ( err && err.message ) || __( 'Failed to create translation.', 'perflocale' ),
						status:  ( err && err.data && typeof err.data.status === 'number' ) ? err.data.status : null
					}
				} ) );

				// Re-sync: the translation may already exist (created in
				// another tab) or the source state changed under us.
				fetchTranslations();
			} );
		}

		function renderRow( lang ) {
			var isCurrent = lang.is_current;
			var hasTranslation = lang.has_translation;

			var actionEl;

			// Unassigned post: every row is a "Set as <lang>" button so the
			// user can anchor this post to a language before translating.
			if ( isUnassigned ) {
				actionEl = el( 'button', {
					className: 'perflocale-panel-create',
					disabled: busySlug === lang.slug,
					onClick: function() { handleAssignLanguage( lang.slug ); }
				},
					busySlug === lang.slug
						? el( 'span', { className: 'perflocale-panel-spinner' } )
						: ( i18n.setAs || 'Set as' ) + ' ' + ( lang.bcp47 || lang.slug.toUpperCase() )
				);

				return el( 'div', {
					key: lang.slug,
					className: 'perflocale-panel-row'
				},
					el( 'div', { className: 'perflocale-panel-row__info' },
						el( 'span', { className: 'perflocale-panel-badge' }, lang.bcp47 || lang.slug.toUpperCase() ),
						el( 'span', { className: 'perflocale-panel-name' }, lang.native_name || lang.name )
					),
					el( 'div', { className: 'perflocale-panel-row__action' }, actionEl )
				);
			}

			if ( isCurrent ) {
				// Current post - just show "Current".
				actionEl = el( 'span', {
					className: 'perflocale-panel-status',
					style: { color: '#16a34a', background: '#dcfce7' }
				}, i18n.current || 'Current' );
			} else if ( hasTranslation ) {
				actionEl = el( 'a', {
					className: 'perflocale-panel-edit',
					href: lang.edit_url,
					target: '_blank'
				}, i18n.edit || __( 'Edit', 'perflocale' ) );
			} else {
				actionEl = el( 'button', {
					className: 'perflocale-panel-create',
					disabled: busySlug === lang.slug,
					onClick: function() { handleCreate( lang.slug ); }
				},
					busySlug === lang.slug
						? el( 'span', { className: 'perflocale-panel-spinner' } )
						: '+ ' + ( i18n.create || __( 'Create', 'perflocale' ) )
				);
			}

			return el( 'div', {
				key: lang.slug,
				className: 'perflocale-panel-row' + ( isCurrent ? ' perflocale-panel-row--current' : '' )
			},
				el( 'div', { className: 'perflocale-panel-row__info' },
					el( 'span', { className: 'perflocale-panel-badge' }, lang.bcp47 || lang.slug.toUpperCase() ),
					el( 'span', { className: 'perflocale-panel-name' }, lang.native_name || lang.name )
				),
				el( 'div', { className: 'perflocale-panel-row__action' }, actionEl )
			);
		}

		var content;

		if ( isLoading ) {
			content = el( 'div', { className: 'perflocale-panel-loading' },
				el( 'span', { className: 'perflocale-panel-spinner' } ),
				' ' + __( 'Loading...', 'perflocale' )
			);
		} else if ( languages.length === 0 ) {
			content = el( 'p', {
				style: { color: '#6b7280', margin: 0, fontSize: '13px' }
			}, __( 'No languages configured.', 'perflocale' ) );
		} else {
			var unassignedNote = isUnassigned
				? el( 'p', {
					className: 'perflocale-panel-note',
					style: { margin: '0 0 10px', padding: '8px 10px', background: '#fef3c7', border: '1px solid #fcd34d', borderRadius: '4px', fontSize: '13px', color: '#78350f' }
				}, i18n.unassignedNote || 'This post has no language yet. Pick the language it is written in:' )
				: null;

			// The opt-out toggles render only when the post type's meta is
			// registered for REST (postMeta is an object then) - they mirror
			// the classic metabox checkboxes, same meta keys, same semantics.
			var syncOptoutToggle = null;

			if ( postMeta && typeof postMeta === 'object' ) {
				syncOptoutToggle = el( 'div', { style: { marginTop: '10px', paddingTop: '10px', borderTop: '1px solid #e5e7eb' } },
					el( ToggleControl, {
						label: __( 'Independent across languages', 'perflocale' ),
						help: __( 'Do not sync this post’s shared fields (featured image, builder layout, configured sync fields) with its translations, in either direction.', 'perflocale' ),
						checked: postMeta._perflocale_sync_optout === 'yes',
						onChange: function ( value ) {
							editPost( { meta: { _perflocale_sync_optout: value ? 'yes' : '' } } );
						},
						__nextHasNoMarginBottom: true
					} ),
					el( ToggleControl, {
						label: __( 'Hide from hreflang & sitemap alternates', 'perflocale' ),
						help: __( 'Search engines will not be told this translation is an alternate of its siblings.', 'perflocale' ),
						checked: postMeta._perflocale_seo_exclude === 'yes',
						onChange: function ( value ) {
							editPost( { meta: { _perflocale_seo_exclude: value ? 'yes' : '' } } );
						},
						__nextHasNoMarginBottom: true
					} )
				);
			}

			content = el( 'div', null,
				unassignedNote,
				el( 'div', { className: 'perflocale-panel-rows' },
					languages.map( renderRow )
				),
				syncOptoutToggle
			);
		}

		return el( PluginDocumentSettingPanel, {
			name: 'perflocale-translations',
			title: i18n.panelTitle || 'Translations',
			className: 'perflocale-document-panel'
		}, content );
	}

	registerPlugin( 'perflocale', {
		render: PerfLocalePanel,
		icon: 'translation'
	} );

	// Middleware: inject the current post ID into term + search REST API
	// requests so the server can filter results by the post's language.
	// `search` is what powers the Gutenberg link picker and ToC block - both
	// must list only same-language pages, otherwise authors of a /de/ post
	// get suggestions that link to the default-language URLs.
	//
	// The collection segment stays open-ended so custom taxonomies (whose
	// rest_base can't be enumerated on the client) are still covered. The core
	// boot collections below are never language-scoped server-side, and core
	// preloads them by exact path on editor open; tagging their path would
	// miss that preload and force an avoidable REST round-trip each time.
	// OPTIONS permission probes are likewise never filtered.
	var NEVER_LANGUAGE_FILTERED = {
		types: 1, taxonomies: 1, statuses: 1, themes: 1, settings: 1,
		users: 1, media: 1, blocks: 1, 'block-types': 1, 'block-patterns': 1,
		'block-renderer': 1, templates: 1, 'template-parts': 1,
		'global-styles': 1, menus: 1, 'menu-items': 1, 'menu-locations': 1,
		sidebars: 1, widgets: 1, 'widget-types': 1
	};

	apiFetch.use( function( options, next ) {
		var method = ( options.method || 'GET' ).toUpperCase();
		var match  = ( method === 'GET' && options.path )
			? options.path.match( /\/wp\/v2\/([a-z_-]+)(?:\?|$)/ )
			: null;

		if ( match && ! NEVER_LANGUAGE_FILTERED[ match[ 1 ] ] ) {
			var postId = config.postId;

			if ( ! postId && wp.data ) {
				var editor = wp.data.select( 'core/editor' );
				if ( editor ) {
					postId = editor.getCurrentPostId();
				}
			}

			if ( postId ) {
				var separator = options.path.indexOf( '?' ) !== -1 ? '&' : '?';
				options.path += separator + 'perflocale_post=' + postId;
			}
		}

		return next( options );
	} );

	// After a save, say when its Sync Fields did not reach some translations
	// because the user may not edit them. The save's REST response carries
	// the count as perflocale_sync_skipped (0 when none), which the editor
	// keeps on the current post record.
	var wasSaving = false;

	wp.data.subscribe( function() {
		var editor = wp.data.select( 'core/editor' );

		if ( ! editor || ! editor.isSavingPost ) {
			return;
		}

		var saving   = editor.isSavingPost() && ! editor.isAutosavingPost();
		var finished = wasSaving && ! saving;

		// Recorded before any dispatch: a dispatch runs every subscriber
		// again at once, this one included.
		wasSaving = saving;

		if ( finished && editor.didPostSaveRequestSucceed() ) {
			var skipped = parseInt( editor.getCurrentPost().perflocale_sync_skipped, 10 ) || 0;
			var notices = wp.data.dispatch( 'core/notices' );

			if ( skipped > 0 ) {
				notices.createNotice(
					'warning',
					wp.i18n.sprintf(
						/* translators: %d: number of translations. */
						wp.i18n._n( 'Sync Fields were not copied to %d translation that you cannot edit.', 'Sync Fields were not copied to %d translations that you cannot edit.', skipped, 'perflocale' ),
						skipped
					),
					{ id: 'perflocale-sync-skipped', isDismissible: true }
				);
			} else if ( wp.data.select( 'core/notices' ).getNotices().some( function( notice ) {
				return notice.id === 'perflocale-sync-skipped';
			} ) ) {
				notices.removeNotice( 'perflocale-sync-skipped' );
			}
		}
	} );
} )();
