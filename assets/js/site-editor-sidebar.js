/**
 * PerfLocale - Site Editor translations panel.
 *
 * The post editor has had a translations panel since 1.0.0, but the Site Editor
 * never did: EditorSidebar only loads on `$screen->base === 'post'`, and the
 * Site Editor's base is `site-editor`. So templates and template parts - the
 * one family of translatable objects you cannot open in the post editor - were
 * the only ones with no way to see their translations while editing them.
 *
 * This renders the same panel, from the same REST endpoint, in the Site Editor's
 * document sidebar. A language row with no translation offers Create, which
 * calls the route the post-editor panel's Create calls
 * (POST perflocale/v1/translations/post/<id>) with the open entity's numeric
 * id. The canvas keeps editing the entity it has open; the panel loads its rows
 * again, so the new translation shows with its Edit link. A menu translation
 * that is a draft or pending also offers Publish (the Site Editor has no
 * status control for menus). After a Create on a
 * navigation menu or a synced pattern, whose copy keeps the source's name, the
 * panel also shows a hint to rename the new translation in its language, with
 * that translation's Edit link.
 *
 * The second part of this file adds a notice to a Navigation block or synced
 * pattern that a translated template or part shares with the original language,
 * and the third adds each menu's language after its name in the Site Editor's
 * Navigation list (each part has its own comment).
 *
 * ⭐ WHY IT READS THE ENTITY FROM THE STORE, NOT FROM PHP.
 * The post editor knows its post at page load, so EditorSidebar can localise the
 * id. The Site Editor is a single-page app: you move between the 404 template,
 * the header part and back without a reload, and `getCurrentPost()` changes
 * underneath. Anything localised at page load would describe whichever entity
 * happened to be open first. The panel therefore subscribes to the store and
 * re-fetches when the entity's `wp_id` changes - and only then.
 *
 * @package PerfLocale
 */

/* global wp, perflocaleSiteEditor */
( function () {
	'use strict';

	if ( ! window.wp || ! wp.plugins || ! wp.element || ! wp.data ) {
		return;
	}

	// `PluginDocumentSettingPanel` moved to @wordpress/editor when the two
	// editors were unified; wp.editPost still re-exports it on the versions
	// this plugin supports. Prefer the new home, fall back to the old one, and
	// register nothing at all if neither exists rather than throwing inside the
	// editor.
	var DocumentPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel ) ||
		null;

	if ( ! DocumentPanel ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useSelect = wp.data.useSelect;
	var apiFetch = wp.apiFetch;

	var config = window.perflocaleSiteEditor || {};
	var i18n = config.i18n || {};
	var supported = config.postTypes || [];

	/**
	 * Which badge to show for a language row.
	 *
	 * Deliberately keyed on the POST status, not the stored link status. A link
	 * is stamped once when the translation is created and never maintained, so a
	 * fully translated, published template can still read `empty` - and in this
	 * panel that would be actively misleading. What an operator needs to know
	 * here is whether the translation is IN USE, and for templates that is
	 * exactly "is it published": WordPress ignores a draft template and renders
	 * the original instead.
	 *
	 * @param {Object} row One language entry from the REST response.
	 * @return {Object} { label, hint, modifier }
	 */
	function describe( row ) {
		if ( ! row.has_translation ) {
			return { label: i18n.none || 'None', hint: '', modifier: 'none' };
		}

		if ( row.post_status === 'publish' ) {
			return { label: i18n.published || 'Published', hint: '', modifier: 'published' };
		}

		if ( row.post_status === 'draft' || row.post_status === 'pending' ) {
			return {
				label: i18n.draft || 'Draft',
				// The single most useful sentence in this panel.
				hint: i18n.draftHint || 'Not used until published - the original renders instead.',
				modifier: 'draft',
			};
		}

		if ( row.post_status === 'trash' ) {
			return { label: i18n.trashed || 'Trashed', hint: '', modifier: 'draft' };
		}

		return { label: row.status_label || '', hint: '', modifier: 'published' };
	}

	function PerfLocaleSiteEditorPanel() {
		// One subscription, and it only re-renders this panel when one of these
		// three primitives actually changes - not on every keystroke in the
		// canvas, which a naive `getCurrentPost()` selector would do because it
		// returns a fresh object each time.
		var entity = useSelect( function ( select ) {
			var editor = select( 'core/editor' );

			if ( ! editor || ! editor.getCurrentPost ) {
				return { wpId: 0, type: '', title: '' };
			}

			var post = editor.getCurrentPost() || {};

			// ⚠️ TWO DIFFERENT ID SHAPES LIVE IN THIS STORE.
			//
			// Templates and template parts are addressed by a STRING id
			// ("theme//slug") and carry the numeric post id separately as
			// `wp_id`, which is absent entirely while the template still comes
			// from the theme's files. Patterns (wp_block) and navigation menus
			// (wp_navigation) are ordinary posts: `id` is already the numeric
			// id and there is no `wp_id` at all.
			//
			// Reading only `wp_id` therefore worked for templates and silently
			// reported "no post yet" for every pattern and menu - they rendered
			// the "this still comes from the theme" notice, which is not even
			// true of them.
			var numericId = 0;

			if ( post.wp_id ) {
				numericId = parseInt( post.wp_id, 10 ) || 0;
			} else if ( typeof post.id === 'number' ) {
				numericId = post.id;
			} else if ( typeof post.id === 'string' && /^[0-9]+$/.test( post.id ) ) {
				numericId = parseInt( post.id, 10 ) || 0;
			}

			return {
				wpId: numericId,
				type: post.type || '',
				title: ( post.title && post.title.rendered ) || post.slug || '',
			};
		}, [] );

		var languagesState = useState( [] );
		var languages = languagesState[ 0 ];
		var setLanguages = languagesState[ 1 ];

		var loadingState = useState( false );
		var isLoading = loadingState[ 0 ];
		var setIsLoading = loadingState[ 1 ];

		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];

		// Same "is this still the latest?" token the post-editor panel uses: an
		// AbortController is not portable across every apiFetch middleware
		// chain, and a slow response must never overwrite a newer one. Held in a
		// ref so it survives re-renders.
		var tokenRef = useRef( 0 );

		// "<source id>:<language slug>" of the Create request in flight, so the
		// spinner never shows on a row of another entity opened meanwhile.
		var busyState = useState( '' );
		var busyKey = busyState[ 0 ];
		var setBusyKey = busyState[ 1 ];

		// True when the open entity has no language yet. Create then gives it
		// the default language, so the default-language row offers no Create.
		var unassignedState = useState( false );
		var isUnassigned = unassignedState[ 0 ];
		var setIsUnassigned = unassignedState[ 1 ];

		// Bumped after a Create request settles, to load the rows again.
		var reloadState = useState( 0 );
		var reload = reloadState[ 0 ];
		var setReload = reloadState[ 1 ];

		// The rename hint shown after a Create on a menu or pattern:
		// { sourceId, slug, type } of that Create, or null.
		var renameState = useState( null );
		var renameHint = renameState[ 0 ];
		var setRenameHint = renameState[ 1 ];

		// The hint belongs to the entity it was created from.
		useEffect(
			function () {
				setRenameHint( null );
			},
			[ entity.wpId ]
		);

		useEffect(
			function () {
				// A template that still comes from the theme has no post yet, so
				// there is nothing to fetch and nothing to translate. Clear any
				// rows left over from the previously opened entity.
				if ( ! entity.wpId || supported.indexOf( entity.type ) === -1 ) {
					setLanguages( [] );
					setIsUnassigned( false );
					setError( '' );
					return;
				}

				var myToken = ++tokenRef.current;

				setIsLoading( true );
				setError( '' );

				apiFetch( { path: 'perflocale/v1/translations/post/' + entity.wpId } )
					.then( function ( response ) {
						if ( myToken !== tokenRef.current ) {
							return;
						}

						setLanguages( ( response && response.languages ) || [] );
						setIsUnassigned( !! ( response && response.is_unassigned ) );
						setIsLoading( false );
					} )
					.catch( function ( err ) {
						if ( myToken !== tokenRef.current ) {
							return;
						}

						setLanguages( [] );
						setIsUnassigned( false );
						setError( ( err && err.message ) || i18n.loadError || 'Could not load translations.' );
						setIsLoading( false );
					} );
			},
			// wpId is the whole dependency: moving between entities changes it,
			// and typing in the canvas does not. `reload` changes only after a
			// Create request.
			[ entity.wpId, entity.type, reload ]
		);

		/**
		 * Create the translation of the open entity in one language.
		 *
		 * The same request the post-editor panel sends: the route checks
		 * perflocale_translate and edit rights on the source, then the copy
		 * source and the type's create capability, and copies the default
		 * language's content. The canvas is left on the entity it has open.
		 *
		 * @param {string} slug Language slug of the row.
		 */
		function handleCreate( slug ) {
			var sourceId = entity.wpId;
			var sourceType = entity.type;
			var notices = wp.data.dispatch( 'core/notices' );

			if ( ! sourceId || busyKey ) {
				return;
			}

			setBusyKey( sourceId + ':' + slug );

			apiFetch( {
				path: 'perflocale/v1/translations/post/' + sourceId,
				method: 'POST',
				data: { target_lang: slug, copy_content: true },
			} )
				.then( function () {
					setBusyKey( '' );

					if ( notices ) {
						notices.createNotice( 'success', i18n.created || 'Translation created.', {
							type: 'snackbar',
							isDismissible: true,
						} );
					}

					// A copied menu or pattern keeps the source's name.
					if ( sourceType === 'wp_navigation' || sourceType === 'wp_block' ) {
						setRenameHint( { sourceId: sourceId, slug: slug, type: sourceType } );
					}

					setReload( function ( n ) {
						return n + 1;
					} );
				} )
				.catch( function ( err ) {
					setBusyKey( '' );

					// The route's own message says why (for example no right to
					// create this type); the rows load again in case the
					// translation was created elsewhere meanwhile.
					if ( notices ) {
						notices.createNotice(
							'error',
							( err && err.message ) || i18n.createError || 'Failed to create translation.',
							{ type: 'snackbar', isDismissible: true }
						);
					}

					setReload( function ( n ) {
						return n + 1;
					} );
				} );
		}

		/**
		 * Publish a draft or pending menu translation.
		 *
		 * The Site Editor has no status control for navigation menus. This
		 * saves the menu's status through core's own route
		 * (POST /wp/v2/navigation/<id>, through the core data store, so the
		 * editor's copy of the menu is updated too), which checks the user's
		 * rights to edit and publish it exactly as any other save does.
		 *
		 * @param {Object} row Language row of the translation.
		 */
		function handlePublish( row ) {
			var notices = wp.data.dispatch( 'core/notices' );
			var postId = parseInt( row.post_id, 10 ) || 0;

			if ( ! postId || busyKey ) {
				return;
			}

			setBusyKey( 'publish:' + postId );

			wp.data
				.dispatch( 'core' )
				.saveEntityRecord( 'postType', 'wp_navigation', { id: postId, status: 'publish' }, { throwOnError: true } )
				.then( function () {
					setBusyKey( '' );

					if ( notices ) {
						notices.createNotice( 'success', i18n.publishedNote || 'Translation published.', {
							type: 'snackbar',
							isDismissible: true,
						} );
					}

					setReload( function ( n ) {
						return n + 1;
					} );
				} )
				.catch( function ( err ) {
					setBusyKey( '' );

					if ( notices ) {
						notices.createNotice(
							'error',
							( err && err.message ) || i18n.publishError || 'Could not publish the translation.',
							{ type: 'snackbar', isDismissible: true }
						);
					}

					setReload( function ( n ) {
						return n + 1;
					} );
				} );
		}

		if ( supported.indexOf( entity.type ) === -1 ) {
			return null;
		}

		var body;

		if ( ! entity.wpId ) {
			// Only a TEMPLATE can legitimately have no post behind it - that is
			// the theme-file state, and it is the one people misread (nothing to
			// translate yet because nothing has been customised). A pattern or a
			// menu always has a post, so if the id is missing there we are simply
			// still waiting for the store rather than looking at a theme file.
			var isTemplateType =
				entity.type === 'wp_template' || entity.type === 'wp_template_part';

			body = el(
				'p',
				{ className: 'perflocale-panel-loading' },
				isTemplateType
					? i18n.notCustomised ||
							'This still comes from the theme. Save a change to customise it, then its translations appear here.'
					: i18n.loading || 'Loading...'
			);
		} else if ( isLoading && ! languages.length ) {
			body = el( 'p', { className: 'perflocale-panel-loading' }, i18n.loading || 'Loading...' );
		} else if ( error ) {
			body = el( 'p', { className: 'perflocale-panel-loading' }, error );
		} else if ( ! languages.length ) {
			body = el( 'p', { className: 'perflocale-panel-loading' }, i18n.none || 'None' );
		} else {
			body = el(
				'div',
				{ className: 'perflocale-panel-rows' },
				languages.map( function ( row ) {
					var state = describe( row );
					var isCurrent = !! row.is_current;

					var info = [
						el(
							'span',
							{ className: 'perflocale-panel-name', key: 'name' },
							row.native_name || row.name || row.slug
						),
						el(
							'span',
							{
								className:
									'perflocale-panel-status perflocale-panel-status--' + state.modifier,
								key: 'status',
							},
							isCurrent ? i18n.current || 'Current' : state.label
						),
					];

					if ( state.hint && ! isCurrent ) {
						info.push(
							el( 'span', { className: 'perflocale-panel-hint', key: 'hint' }, state.hint )
						);
					}

					// Only an entity we can actually open gets a link. `edit_url`
					// is built server-side by ObjectLinks, which resolves a
					// template to its Site Editor route rather than post.php.
					// A language with no translation gets Create instead.
					var action = null;

					// A menu translation that is not published yet: Publish, the
					// open menu included.
					var publishButton = null;

					if (
						entity.type === 'wp_navigation' &&
						row.has_translation &&
						row.post_id &&
						( row.post_status === 'draft' || row.post_status === 'pending' )
					) {
						var publishBusy = busyKey === 'publish:' + row.post_id;

						publishButton = el(
							'button',
							{
								type: 'button',
								className: 'perflocale-panel-create perflocale-panel-publish',
								disabled: !! busyKey,
								'aria-busy': publishBusy ? 'true' : undefined,
								onClick: function () {
									handlePublish( row );
								},
								key: 'publish',
							},
							publishBusy ? el( 'span', { className: 'perflocale-panel-spinner' } ) : i18n.publish || 'Publish'
						);
					}

					if ( ! isCurrent && row.has_translation && row.edit_url ) {
						action = el(
							'a',
							{
								className: 'perflocale-panel-edit',
								href: row.edit_url,
								key: 'edit',
							},
							i18n.edit || 'Edit'
						);
					} else if ( ! isCurrent && ! row.has_translation && ! ( isUnassigned && row.is_default ) ) {
						var rowBusy = busyKey === entity.wpId + ':' + row.slug;

						action = el(
							'button',
							{
								type: 'button',
								className: 'perflocale-panel-create',
								disabled: !! busyKey,
								'aria-busy': rowBusy ? 'true' : undefined,
								onClick: function () {
									handleCreate( row.slug );
								},
								key: 'create',
							},
							rowBusy
								? el( 'span', { className: 'perflocale-panel-spinner' } )
								: '+ ' + ( i18n.create || 'Create' )
						);
					}

					return el(
						'div',
						{
							className:
								'perflocale-panel-row' +
								( isCurrent ? ' perflocale-panel-row--current' : '' ),
							key: row.slug,
						},
						el( 'div', { className: 'perflocale-panel-row__info' }, info ),
						action || publishButton
							? el( 'div', { className: 'perflocale-panel-row__action' }, publishButton, action )
							: null
					);
				} )
			);
		}

		// After a Create on a menu or pattern: the copy has the source's name,
		// and a menu's name is the accessible name of its navigation on the
		// site. The hint names the language and links to the new translation
		// once the rows have loaded it.
		var hint = null;

		if ( renameHint && renameHint.sourceId === entity.wpId && languages.length ) {
			var hintRow = null;

			for ( var i = 0; i < languages.length; i++ ) {
				if ( languages[ i ].slug === renameHint.slug ) {
					hintRow = languages[ i ];
					break;
				}
			}

			var hintFormat =
				renameHint.type === 'wp_navigation'
					? i18n.renameMenu ||
						'The %s menu still has the original name. Rename it in its language: screen readers read the menu name aloud to visitors.'
					: i18n.renamePattern ||
						'The %s pattern still has the original name. Rename it in its language, so the pattern lists tell them apart.';
			var hintName = hintRow ? hintRow.native_name || hintRow.name || hintRow.slug : renameHint.slug;
			var hintEdit = hintRow && hintRow.has_translation && hintRow.edit_url ? hintRow.edit_url : '';

			hint = el(
				'div',
				{ className: 'perflocale-panel-notice', role: 'status' },
				el( 'p', { className: 'perflocale-panel-notice__text' }, wp.i18n.sprintf( hintFormat, hintName ) ),
				el(
					'div',
					{ className: 'perflocale-panel-notice__actions' },
					hintEdit
						? el( 'a', { className: 'perflocale-panel-edit', href: hintEdit }, i18n.edit || 'Edit' )
						: null,
					el(
						'button',
						{
							type: 'button',
							className: 'perflocale-panel-notice__close',
							onClick: function () {
								setRenameHint( null );
							},
						},
						i18n.close || 'Close'
					)
				)
			);
		}

		return el(
			DocumentPanel,
			{
				name: 'perflocale-site-editor-translations',
				title: i18n.panelTitle || 'Translations',
				className: 'perflocale-site-editor-panel',
			},
			hint,
			body
		);
	}

	wp.plugins.registerPlugin( 'perflocale-site-editor-translations', {
		render: PerfLocaleSiteEditorPanel,
	} );
}() );

/**
 * A notice on a Navigation block or synced pattern that a translated template
 * or template part shares with the original language.
 *
 * A translated template or part, such as "Header (de)", is a copy of the
 * original. Its Navigation block names a menu and its synced pattern names a
 * pattern; editing either inside it edits that menu or pattern for every
 * language that shows it. While the open entity is a template or template
 * part in a language other than the default, and the selected block still
 * names the original-language menu or pattern (or one with no language), the
 * block's settings show "This menu is shared with <language>. Edit its
 * <language> translation instead." with Open (the translation's edit link) or
 * Create.
 *
 * Create sends the request the Translations panel sends. When the block can
 * open the new translation (a menu published or draft, a pattern published,
 * draft or pending), the block in the canvas is then pointed at it, so saving
 * the template stores it; when the template still names what its original
 * names, the server has already stored that reference.
 *
 * Reads go through perflocale/v1/translations/post/<id>, once per id and page
 * load, and only for a selected block.
 */
( function () {
	'use strict';

	var config = window.perflocaleSiteEditor || {};
	var types = config.postTypes || [];

	if (
		( types.indexOf( 'wp_template_part' ) === -1 && types.indexOf( 'wp_template' ) === -1 ) ||
		! window.wp ||
		! wp.hooks ||
		! wp.compose ||
		! wp.blockEditor ||
		! wp.blockEditor.InspectorControls ||
		! wp.components ||
		! wp.element ||
		! wp.data ||
		! wp.apiFetch
	) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useSelect = wp.data.useSelect;
	var apiFetch = wp.apiFetch;
	var i18n = config.i18n || {};

	// The post type each block references.
	var BLOCKS = {
		'core/navigation': 'wp_navigation',
		'core/block': 'wp_block',
	};

	// Statuses the block opens in the editor, per referenced post type.
	var OPENABLE = {
		wp_navigation: [ 'publish', 'draft' ],
		wp_block: [ 'publish', 'draft', 'pending' ],
	};

	var requests = {};

	/**
	 * The translations response for a post id, fetched once per page load.
	 *
	 * @param {number} id Post id.
	 * @return {Promise} Response, or null when it could not be read.
	 */
	function translationsOf( id ) {
		if ( ! requests[ id ] ) {
			requests[ id ] = apiFetch( { path: 'perflocale/v1/translations/post/' + id } ).catch( function () {
				delete requests[ id ];
				return null;
			} );
		}

		return requests[ id ];
	}

	function rowWhere( response, test ) {
		var rows = ( response && response.languages ) || [];

		for ( var i = 0; i < rows.length; i++ ) {
			if ( test( rows[ i ] ) ) {
				return rows[ i ];
			}
		}

		return null;
	}

	function languageName( row ) {
		return row ? row.native_name || row.name || row.slug : '';
	}

	function SharedNotice( props ) {
		var refType = BLOCKS[ props.name ];
		var ref = parseInt( props.attributes && props.attributes.ref, 10 ) || 0;

		var part = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			var post = editor && editor.getCurrentPost ? editor.getCurrentPost() || {} : {};

			return {
				type: post.type || '',
				wpId: post.wp_id ? parseInt( post.wp_id, 10 ) || 0 : 0,
			};
		}, [] );

		var state = useState( null );
		var notice = state[ 0 ];
		var setNotice = state[ 1 ];

		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		var reloadState = useState( 0 );
		var reload = reloadState[ 0 ];
		var setReload = reloadState[ 1 ];

		useEffect(
			function () {
				var live = true;

				setNotice( null );

				if ( ( part.type !== 'wp_template_part' && part.type !== 'wp_template' ) || ! part.wpId || ! ref || types.indexOf( refType ) === -1 ) {
					return undefined;
				}

				Promise.all( [ translationsOf( part.wpId ), translationsOf( ref ) ] ).then( function ( responses ) {
					if ( ! live ) {
						return;
					}

					var partLang = rowWhere( responses[ 0 ], function ( r ) {
						return r.is_current;
					} );

					// Only a translation: the original template or part is where
					// the original menu and pattern belong.
					if ( ! partLang || partLang.is_default || ! responses[ 1 ] ) {
						return;
					}

					var refLang = rowWhere( responses[ 1 ], function ( r ) {
						return r.is_current;
					} );
					var original = responses[ 1 ].is_unassigned
						? rowWhere( responses[ 1 ], function ( r ) {
								return r.is_default;
						  } )
						: refLang;

					if ( ! original || ! original.is_default ) {
						return;
					}

					var target = rowWhere( responses[ 1 ], function ( r ) {
						return r.slug === partLang.slug;
					} );

					setNotice( {
						from: languageName( original ),
						to: languageName( partLang ),
						slug: partLang.slug,
						target: target,
					} );
				} );

				return function () {
					live = false;
				};
			},
			[ part.type, part.wpId, ref, refType, reload ]
		);

		if ( ! notice ) {
			return null;
		}

		function create() {
			var notices = wp.data.dispatch( 'core/notices' );

			if ( busy ) {
				return;
			}

			setBusy( true );

			apiFetch( {
				path: 'perflocale/v1/translations/post/' + ref,
				method: 'POST',
				data: { target_lang: notice.slug, copy_content: true },
			} )
				.then( function () {
					delete requests[ ref ];

					if ( notices ) {
						notices.createNotice( 'success', i18n.created || 'Translation created.', {
							type: 'snackbar',
							isDismissible: true,
						} );
					}

					return translationsOf( ref );
				} )
				.then( function ( response ) {
					setBusy( false );

					var created = rowWhere( response, function ( r ) {
						return r.slug === notice.slug;
					} );

					// The block names the new translation; saving the template
					// stores it.
					if (
						created &&
						created.post_id &&
						( OPENABLE[ refType ] || [] ).indexOf( created.post_status ) !== -1
					) {
						props.setAttributes( { ref: created.post_id } );
					} else {
						setReload( function ( n ) {
							return n + 1;
						} );
					}
				} )
				.catch( function ( err ) {
					setBusy( false );

					if ( notices ) {
						notices.createNotice(
							'error',
							( err && err.message ) || i18n.createError || 'Failed to create translation.',
							{ type: 'snackbar', isDismissible: true }
						);
					}
				} );
		}

		var format =
			refType === 'wp_navigation'
				? i18n.sharedMenu || 'This menu is shared with %1$s. Edit its %2$s translation instead.'
				: i18n.sharedPattern || 'This pattern is shared with %1$s. Edit its %2$s translation instead.';

		var action = null;

		if ( notice.target && notice.target.has_translation && notice.target.edit_url ) {
			action = el(
				'a',
				{ className: 'perflocale-panel-edit perflocale-shared-notice__open', href: notice.target.edit_url },
				i18n.open || 'Open'
			);
		} else if ( notice.target && ! notice.target.has_translation ) {
			action = el(
				'button',
				{
					type: 'button',
					className: 'perflocale-panel-create perflocale-shared-notice__create',
					disabled: busy,
					'aria-busy': busy ? 'true' : undefined,
					onClick: create,
				},
				busy ? el( 'span', { className: 'perflocale-panel-spinner' } ) : '+ ' + ( i18n.create || 'Create' )
			);
		}

		var box = el(
			'div',
			{ className: 'perflocale-panel-notice perflocale-shared-notice', role: 'status' },
			el( 'p', { className: 'perflocale-panel-notice__text' }, wp.i18n.sprintf( format, notice.from, notice.to ) ),
			action ? el( 'div', { className: 'perflocale-panel-notice__actions' }, action ) : null
		);

		// The Navigation block's settings open on its menu list ("list" group),
		// which is where its labels are edited; its other settings are on a
		// second tab. The notice shows on both.
		if ( refType === 'wp_navigation' ) {
			return el(
				Fragment,
				null,
				el( wp.blockEditor.InspectorControls, { group: 'list' }, box ),
				el( wp.blockEditor.InspectorControls, null, box )
			);
		}

		// A synced pattern is a section block: its settings show the "content"
		// group, not the default one (WordPress 7.0+). Older versions show the
		// default group.
		return el(
			Fragment,
			null,
			el( wp.blockEditor.InspectorControls, { group: 'content' }, box ),
			el( wp.blockEditor.InspectorControls, null, box )
		);
	}

	var withSharedNotice = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( ! BLOCKS[ props.name ] || ! props.isSelected ) {
				return el( BlockEdit, props );
			}

			return el( Fragment, null, el( SharedNotice, props ), el( BlockEdit, props ) );
		};
	}, 'withPerfLocaleSharedNotice' );

	wp.hooks.addFilter( 'editor.BlockEdit', 'perflocale/shared-notice', withSharedNotice );
}() );

/**
 * The Site Editor's Navigation list: the language after each menu's name.
 *
 * A translation created from a menu keeps the menu's name, so the Navigation
 * list shows several menus with one name. While navigation menus are
 * translatable, this adds the language code after each listed menu's name,
 * for example "Hoofdmenu (EN)".
 *
 * Display only. The code is a separate span added to the list item's label;
 * nothing is written to the editor's data, so the menu's stored name, the
 * Rename field, Duplicate and the site's navigation keep the real name.
 *
 * The language is the `perflocale_language` field of the menu's record in
 * the `core` data store, which the list itself loaded from
 * GET /wp/v2/navigation in the edit context. A list item is matched to its
 * menu through the id in its `href` (a link, or a button in newer Site
 * Editors): `p=/wp_navigation/<id>`, or `postType=wp_navigation&postId=<id>`
 * in older Site Editors.
 */
( function () {
	'use strict';

	var types = ( window.perflocaleSiteEditor && window.perflocaleSiteEditor.postTypes ) || [];

	if ( types.indexOf( 'wp_navigation' ) === -1 || ! window.wp || ! wp.data || ! wp.data.select || ! window.MutationObserver || ! window.URL ) {
		return;
	}

	var LABEL_CLASS = 'perflocale-menu-language';
	var scheduled = false;

	/**
	 * The navigation menu id a list item's link opens, or 0.
	 *
	 * @param {string} href Link target.
	 * @return {number} Menu id.
	 */
	function menuId( href ) {
		var url;

		try {
			url = new window.URL( href, window.location.href );
		} catch ( e ) {
			return 0;
		}

		var match = /^\/wp_navigation\/([0-9]+)$/.exec( url.searchParams.get( 'p' ) || '' );

		if ( match ) {
			return parseInt( match[ 1 ], 10 ) || 0;
		}

		if ( url.searchParams.get( 'postType' ) === 'wp_navigation' ) {
			return parseInt( url.searchParams.get( 'postId' ) || '', 10 ) || 0;
		}

		return 0;
	}

	/**
	 * The language code shown for a menu, or '' when it has no language.
	 *
	 * @param {number} id Menu id.
	 * @return {string} Upper-case language slug.
	 */
	function languageCode( id ) {
		var core = wp.data.select( 'core' );
		var record = core && core.getEntityRecord ? core.getEntityRecord( 'postType', 'wp_navigation', id ) : null;
		var slug = record ? record.perflocale_language : '';

		return typeof slug === 'string' && slug ? slug.toUpperCase() : '';
	}

	function decorate() {
		scheduled = false;

		var links = document.querySelectorAll( '.edit-site-sidebar-navigation-item[href]' );

		for ( var i = 0; i < links.length; i++ ) {
			var id = menuId( links[ i ].getAttribute( 'href' ) || '' );

			if ( ! id ) {
				continue;
			}

			// The element that holds the menu's name.
			var holder = links[ i ].querySelector( '.components-flex-block' ) || links[ i ];
			var label = holder.querySelector( '.' + LABEL_CLASS );
			var code = languageCode( id );
			var text = code ? ' (' + code + ')' : '';

			if ( ! text ) {
				if ( label ) {
					label.parentNode.removeChild( label );
				}

				continue;
			}

			if ( ! label ) {
				label = document.createElement( 'span' );
				label.className = LABEL_CLASS;
				holder.appendChild( label );
			}

			if ( label.textContent !== text ) {
				label.textContent = text;
			}
		}
	}

	function schedule() {
		if ( scheduled ) {
			return;
		}

		scheduled = true;
		window.requestAnimationFrame( decorate );
	}

	function start() {
		// The list is rendered and re-rendered by the Site Editor; the menu
		// records arrive in the data store, possibly after the list.
		new window.MutationObserver( schedule ).observe( document.body, { childList: true, subtree: true } );
		wp.data.subscribe( schedule );
		schedule();
	}

	if ( document.body ) {
		start();
	} else {
		document.addEventListener( 'DOMContentLoaded', start );
	}
}() );
