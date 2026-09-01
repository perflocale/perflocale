/**
 * PerfLocale - "Translate all blocks" sidebar panel
 *
 * Adds a separate PluginDocumentSettingPanel (alongside the main
 * "Translations" panel from editor-sidebar.js) that batch-translates every
 * translatable block in the open post.
 *
 * Two modes:
 *  - SOURCE post (post.lang === default): "Translate to <X>" buttons per
 *    target language, MT-translates every block in place.
 *  - SIBLING post (post.lang !== default): a single "Fill all from source"
 *    button that pulls each block's content from the corresponding source-
 *    post block and translates it into this sibling's language. Mirrors the
 *    per-block toolbar's sibling-aware behaviour for the post-level case.
 *
 * Uses the public API exposed by block-toolbar.js
 * (`window.perflocaleBlockTranslate.collect / translate / fillAllFromSource`)
 * so this file stays small and the recursive walker / batch logic lives
 * in one place.
 *
 * @package PerfLocale
 */

/* global wp, perflocaleBlockTranslateSidebar */
( function () {
	'use strict';

	if ( ! ( wp && wp.plugins && wp.editPost && wp.element && wp.i18n ) ) {
		return;
	}

	var registerPlugin             = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	var el                         = wp.element.createElement;
	var useState                   = wp.element.useState;
	var __                         = wp.i18n.__;
	var sprintf                    = wp.i18n.sprintf;

	var cfg            = window.perflocaleBlockTranslateSidebar || {};
	var LANGUAGES      = cfg.languages || [];
	var SOURCE         = cfg.postSourceLang || cfg.currentLang || ( LANGUAGES[ 0 ] && LANGUAGES[ 0 ].slug ) || '';
	var MT_READY       = !! cfg.mtReady;
	var MT_SETTINGS_URL = cfg.mtSettingsUrl || '';
	var IS_SIBLING     = !! cfg.isSibling;
	var SIBLING_SOURCE_LANG = cfg.sourceLang || '';
	var SOURCE_POST_ID = cfg.sourcePostId || 0;

	var PROVIDER_LABELS = {
		deepl: 'DeepL',
		google: 'Google',
		microsoft: 'Microsoft',
		libre: 'LibreTranslate',
		external_agency: 'Agency'
	};

	function providerLabel() {
		var p = cfg.mtProvider || '';
		if ( ! p ) { return ''; }
		return PROVIDER_LABELS[ p ] || ( p.charAt( 0 ).toUpperCase() + p.slice( 1 ) );
	}

	function langName( slug ) {
		for ( var i = 0; i < LANGUAGES.length; i++ ) {
			if ( LANGUAGES[ i ].slug === slug ) {
				// Prefer the human name; fall back to BCP 47 (en-GB) over
				// the raw lowercase slug so confirm() dialogs read cleanly.
				return LANGUAGES[ i ].name || LANGUAGES[ i ].bcp47 || slug;
			}
		}
		return slug;
	}

	function ensureApiReady() {
		return window.perflocaleBlockTranslate
			&& typeof window.perflocaleBlockTranslate.collect === 'function'
			&& typeof window.perflocaleBlockTranslate.translate === 'function';
	}

	function BlockTranslatePanel() {
		var busyState = useState( null );
		var busyKey   = busyState[ 0 ];
		var setBusy   = busyState[ 1 ];

		// MT is enabled in Settings but no provider is configured yet - render
		// the panel with a direct link to the Machine Translation settings tab
		// so the user can finish the setup in one click. (When MT is disabled
		// at the Settings level, the script is not enqueued at all - see
		// EditorSidebar::enqueue_sidebar_assets().)
		if ( ! MT_READY ) {
			return el( PluginDocumentSettingPanel, {
				name: 'perflocale-block-translate',
				title: __( 'Block translation', 'perflocale' ),
				className: 'perflocale-block-translate-panel'
			},
				el( 'p', { style: { fontSize: '12px', color: '#6b7280', margin: '0 0 8px' } },
					__( 'No machine-translation provider is configured. Add an API key to enable batch block translation.', 'perflocale' )
				),
				MT_SETTINGS_URL
					? el( 'a', {
						href: MT_SETTINGS_URL,
						style: { fontSize: '12px', fontWeight: 600, textDecoration: 'none' }
					}, __( 'Configure a provider →', 'perflocale' ) )
					: null
			);
		}

		var providerSuffix = providerLabel() ? ' · ' + providerLabel() : '';

		// SIBLING MODE — single "Fill all from source" button. The sibling's
		// own language is the only sensible target; pulling from anywhere
		// other than the configured source post would defeat the purpose.
		if ( IS_SIBLING && SOURCE_POST_ID > 0 && SIBLING_SOURCE_LANG && SOURCE ) {
			var siblingBusy = busyKey === 'sibling';

			function handleFillAll() {
				if ( ! ensureApiReady() ) { return; }

				if ( typeof window.perflocaleBlockTranslate.fillAllFromSource !== 'function' ) {
					wp.data.dispatch( 'core/notices' ).createNotice(
						'error',
						__( 'Sibling fill-from-source not available — reload the editor.', 'perflocale' ),
						{ type: 'snackbar', isDismissible: true }
					);
					return;
				}

				var leaves = window.perflocaleBlockTranslate.collect();

				if ( ! leaves.length ) {
					wp.data.dispatch( 'core/notices' ).createNotice(
						'warning',
						__( 'No translatable text found in this post.', 'perflocale' ),
						{ type: 'snackbar', isDismissible: true }
					);
					return;
				}

				var confirmMsg = sprintf(
					/* translators: 1: number of blocks, 2: source language name */
					__( 'Fill in %1$d blocks from the %2$s source post? Each block will be looked up by position and translated into this sibling. Use Undo to revert; save when satisfied.', 'perflocale' ),
					leaves.length,
					langName( SIBLING_SOURCE_LANG )
				);

				if ( ! window.confirm( confirmMsg ) ) {
					return;
				}

				setBusy( 'sibling' );

				window.perflocaleBlockTranslate.fillAllFromSource( leaves, function () {
					setBusy( null );
				} );
			}

			// Short button label. The previous "Fill all blocks from English (US) source · DeepL"
			// overflowed the 280-px sidebar even before the provider suffix; the
			// surrounding panel description already tells the user that this
			// pulls each block from the source post and translates it. So the
			// button just needs to confirm: which source language, via which
			// provider. Format is: "Translate from English (US) · DeepL".
			var siblingLabel = sprintf(
				/* translators: %s: source language name e.g. "English (US)" */
				__( 'Translate from %s', 'perflocale' ),
				langName( SIBLING_SOURCE_LANG )
			) + providerSuffix;

			return el( PluginDocumentSettingPanel, {
				name: 'perflocale-block-translate',
				title: __( 'Block translation', 'perflocale' ),
				className: 'perflocale-block-translate-panel'
			},
				el( 'p', { style: { fontSize: '12px', color: '#6b7280', margin: '0 0 10px' } },
					__( 'This post is a translation. Pull each block from the source post and translate it into this language.', 'perflocale' )
				),
				el( 'button', {
					className: 'components-button is-secondary',
					style: { justifyContent: 'flex-start', textAlign: 'left', width: '100%' },
					disabled: siblingBusy,
					onClick: handleFillAll
				}, siblingBusy ? __( 'Filling…', 'perflocale' ) : siblingLabel )
			);
		}

		// SOURCE MODE — multi-target MT buttons (original behaviour).
		var targets = LANGUAGES.filter( function ( l ) { return l.slug && l.slug !== SOURCE; } );

		if ( ! targets.length ) {
			return null;
		}

		function handleClick( targetSlug ) {
			if ( ! ensureApiReady() ) { return; }

			var leaves = window.perflocaleBlockTranslate.collect();

			if ( ! leaves.length ) {
				wp.data.dispatch( 'core/notices' ).createNotice(
					'warning',
					__( 'No translatable text found in this post.', 'perflocale' ),
					{ type: 'snackbar', isDismissible: true }
				);
				return;
			}

			// Confirm — destructive, applies translations IN-PLACE in the
			// open editor. Cmd+Z still reverses each block individually.
			// Resolve the BCP 47 form (e.g. en-GB) of the target slug from the
			// payload shipped via wp_localize_script — falls back to the
			// uppercased slug if this language isn't in LANGUAGES (stale ID).
			var targetMeta = LANGUAGES.find( function ( l ) { return l.slug === targetSlug; } );
			var targetTag  = ( targetMeta && targetMeta.bcp47 ) || targetSlug.toUpperCase();
			var confirmMsg = sprintf(
				/* translators: 1: number of blocks, 2: target language tag (BCP 47, e.g. en-GB) */
				__( 'Translate %1$d blocks in this post to %2$s? Translations will be applied in the open editor; use Undo to revert. Save the post when satisfied.', 'perflocale' ),
				leaves.length,
				targetTag
			);

			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}

			setBusy( targetSlug );

			window.perflocaleBlockTranslate.translate( leaves, targetSlug, function () {
				setBusy( null );
			} );
		}

		return el( PluginDocumentSettingPanel, {
			name: 'perflocale-block-translate',
			title: __( 'Block translation', 'perflocale' ),
			className: 'perflocale-block-translate-panel'
		},
			el( 'p', { style: { fontSize: '12px', color: '#6b7280', margin: '0 0 10px' } },
				__( 'Translate every block in this post to the chosen language. Translations are applied in the open editor; save when reviewed.', 'perflocale' )
			),
			el( 'div', { style: { display: 'flex', flexDirection: 'column', gap: '6px' } },
				targets.map( function ( lang ) {
					var label = sprintf(
						/* translators: %s: target language name (or BCP 47 tag fallback, e.g. "en-GB") */
						__( 'Translate to %s', 'perflocale' ),
						lang.name || lang.bcp47 || lang.slug
					) + providerSuffix;

					return el( 'button', {
						key: lang.slug,
						className: 'components-button is-secondary',
						style: { justifyContent: 'flex-start', textAlign: 'left' },
						disabled: busyKey === lang.slug,
						onClick: function () { handleClick( lang.slug ); }
					}, busyKey === lang.slug ? __( 'Translating…', 'perflocale' ) : label );
				} )
			)
		);
	}

	registerPlugin( 'perflocale-block-translate', {
		render: BlockTranslatePanel,
		icon: 'translation'
	} );
}() );
