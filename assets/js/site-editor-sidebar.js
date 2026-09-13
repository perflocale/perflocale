/**
 * PerfLocale - Site Editor translations panel.
 *
 * The post editor has had a translations panel since 1.0.0, but the Site Editor
 * never did: EditorSidebar only loads on `$screen->base === 'post'`, and the
 * Site Editor's base is `site-editor`. So templates and template parts — the
 * one family of translatable objects you cannot open in the post editor — were
 * the only ones with no way to see their translations while editing them.
 *
 * This renders the same panel, from the same REST endpoint, in the Site Editor's
 * document sidebar.
 *
 * ⭐ WHY IT READS THE ENTITY FROM THE STORE, NOT FROM PHP.
 * The post editor knows its post at page load, so EditorSidebar can localise the
 * id. The Site Editor is a single-page app: you move between the 404 template,
 * the header part and back without a reload, and `getCurrentPost()` changes
 * underneath. Anything localised at page load would describe whichever entity
 * happened to be open first. The panel therefore subscribes to the store and
 * re-fetches when the entity's `wp_id` changes — and only then.
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
	 * fully translated, published template can still read `empty` — and in this
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
		// three primitives actually changes — not on every keystroke in the
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
			// reported "no post yet" for every pattern and menu — they rendered
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

		useEffect(
			function () {
				// A template that still comes from the theme has no post yet, so
				// there is nothing to fetch and nothing to translate. Clear any
				// rows left over from the previously opened entity.
				if ( ! entity.wpId || supported.indexOf( entity.type ) === -1 ) {
					setLanguages( [] );
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
						setIsLoading( false );
					} )
					.catch( function ( err ) {
						if ( myToken !== tokenRef.current ) {
							return;
						}

						setLanguages( [] );
						setError( ( err && err.message ) || i18n.loadError || 'Could not load translations.' );
						setIsLoading( false );
					} );
			},
			// wpId is the whole dependency: moving between entities changes it,
			// and typing in the canvas does not.
			[ entity.wpId, entity.type ]
		);

		if ( supported.indexOf( entity.type ) === -1 ) {
			return null;
		}

		var body;

		if ( ! entity.wpId ) {
			// Only a TEMPLATE can legitimately have no post behind it — that is
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
					var action = null;

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
						action ? el( 'div', { className: 'perflocale-panel-row__action' }, action ) : null
					);
				} )
			);
		}

		return el(
			DocumentPanel,
			{
				name: 'perflocale-site-editor-translations',
				title: i18n.panelTitle || 'Translations',
				className: 'perflocale-site-editor-panel',
			},
			body
		);
	}

	wp.plugins.registerPlugin( 'perflocale-site-editor-translations', {
		render: PerfLocaleSiteEditorPanel,
	} );
}() );
