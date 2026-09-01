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

	function PerfLocalePanel() {
		// Per-post sync opt-out flag, read/written through the registered
		// REST meta (all hooks at top level — React rules of hooks).
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
		// call starts, the prior request's response is discarded — that
		// way a slow in-flight request can't overwrite the state with
		// stale data after a faster follow-up has already settled. The
		// alternative AbortController approach isn't portable across all
		// `apiFetch` middleware chains; an "is this still the latest?"
		// token works everywhere. Stored in a ref so it persists across
		// re-renders — a plain `var` would reset to 0 every render and the
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
					setLanguages( response.languages );
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

			setBusySlug( langSlug );
			apiFetch( {
				path: 'perflocale/v1/translations/post/' + config.postId,
				method: 'POST',
				data: { target_lang: langSlug, copy_content: true }
			} ).then( function( response ) {
				setBusySlug( null );
				if ( response && response.edit_url ) {
					// A window.open() here runs in the apiFetch continuation,
					// past the click's user-activation window — Safari blocks
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
				fetchTranslations();
			} ).catch( function() {
				setBusySlug( null );
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
			// registered for REST (postMeta is an object then) — they mirror
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
} )();
