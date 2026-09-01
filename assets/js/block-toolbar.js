/**
 * PerfLocale - Gutenberg block-toolbar extension.
 *
 * Adds a single "Translate" dropdown to supported text blocks with
 * four actions: machine-translate, wrap in a language condition,
 * toggle "do not translate" marker.
 *
 * No build step. Plain JS against global wp.* objects, matching the
 * rest of the plugin's editor-side scripts.
 *
 * @package PerfLocale
 */

/* global wp, perflocaleBlockToolbar */
( function () {
	'use strict';

	var cfg = window.perflocaleBlockToolbar || {};

	if ( ! wp || ! wp.hooks || ! wp.blockEditor || ! wp.components ) {
		return;
	}

	var addFilter = wp.hooks.addFilter;
	var createHOC = wp.compose.createHigherOrderComponent;
	var BlockControls = wp.blockEditor.BlockControls;
	var ToolbarGroup = wp.components.ToolbarGroup;
	var ToolbarDropdownMenu = wp.components.ToolbarDropdownMenu;
	var MenuGroup = wp.components.MenuGroup;
	var MenuItem = wp.components.MenuItem;
	var Modal = wp.components.Modal;
	var Button = wp.components.Button;
	var CheckboxControl = wp.components.CheckboxControl;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var Spinner = wp.components.Spinner;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	// ---------------------------------------------------------------
	// Config + helpers
	// ---------------------------------------------------------------

	var SUPPORTED_BLOCKS = cfg.supportedBlocks || [
		'core/paragraph', 'core/heading', 'core/list-item',
		'core/quote', 'core/pullquote', 'core/button',
		'core/preformatted', 'core/verse', 'core/code'
	];

	var LANGUAGES = cfg.languages || [];
	var CURRENT_LANG = cfg.currentLang || '';

	/**
	 * Format a language slug as "Name (SLUG)" for user-facing strings.
	 * Falls back to the upper-cased slug when the language isn't in the
	 * active list (which can happen if the slug is stale or mistyped).
	 *
	 * Mirrors the in-toolbar menu/select labels at lines 273, 380, 475 so
	 * "Translate to German (de)" in the menu and "Translated to German (DE)"
	 * in the toast read consistently.
	 */
	function formatLangLabel( slug ) {
		if ( ! slug ) { return ''; }

		for ( var i = 0; i < LANGUAGES.length; i++ ) {
			if ( LANGUAGES[ i ].slug === slug ) {
				// Prefer the BCP 47 form shipped from PHP for the visible
				// parenthetical (e.g. "English (UK) (en-GB)"). Falls back
				// to uppercased slug for older payloads or untracked slugs.
				var tag = LANGUAGES[ i ].bcp47 || String( slug ).toUpperCase();
				return ( LANGUAGES[ i ].name || tag ) + ' (' + tag + ')';
			}
		}

		return String( slug ).toUpperCase();
	}
	// Language of the post currently open in the editor. Used as MT source
	// so "Translate to X" always translates FROM what's in the editor,
	// independent of the admin's router language.
	var POST_SOURCE_LANG = cfg.postSourceLang || '';
	var MT_READY = !! cfg.mtReady;
	var MT_SETTINGS_URL = cfg.mtSettingsUrl || '';
	var MT_PROVIDER = cfg.mtProvider || '';

	// Pretty-print provider IDs for the toolbar / toast. Falls back to
	// title-casing the raw ID for unknown providers so a freshly-added
	// provider still gets a reasonable label without a JS update.
	var PROVIDER_LABELS = {
		deepl: 'DeepL',
		google: 'Google',
		microsoft: 'Microsoft',
		libre: 'LibreTranslate',
		external_agency: 'Agency'
	};

	function providerLabel() {
		if ( ! MT_PROVIDER ) { return ''; }
		if ( PROVIDER_LABELS[ MT_PROVIDER ] ) { return PROVIDER_LABELS[ MT_PROVIDER ]; }
		return MT_PROVIDER.charAt( 0 ).toUpperCase() + MT_PROVIDER.slice( 1 );
	}
	// Canonical block attribute that marks a block as "do not translate."
	var SKIP_ATTR = 'perflocaleSkipTranslation';

	// Sibling-detection. When IS_SIBLING is true, the open post
	// is a translation (e.g. /fr/about) of a source-language sibling
	// (e.g. /about). The toolbar swaps the multi-target dropdown for a
	// single "Fill in from <source-lang> source" action.
	var IS_SIBLING     = !! cfg.isSibling;
	var SOURCE_LANG    = cfg.sourceLang    || '';
	var SOURCE_POST_ID = cfg.sourcePostId  || 0;
	var TARGET_POST_ID = cfg.targetPostId  || 0;
	// Editor-canvas-only class applied via the BlockListBlock HOC so the
	// user's own "Additional CSS classes" field is never touched.
	var SKIP_VISUAL_CLASS = 'has-perflocale-skip-translation';

	var i18n = cfg.i18n || {};

	/**
	 * Per-block-type attribute(s) that hold editable text. Most core blocks
	 * use `content`; quote/pullquote use `value`; button uses `text`. We
	 * return an ORDERED LIST so getBlockText can fall back through the chain
	 * when a custom block doesn't follow core's convention.
	 *
	 * The `perflocale.blockToolbar.textAttrs` filter (wp.hooks) lets a
	 * third-party block opt in by returning an extended array - cleanest
	 * extension surface that doesn't require a code change here.
	 */
	/**
	 * Schema-driven text-attribute discovery.
	 *
	 * Reads the block's registered attribute schema (set by the block author
	 * in registerBlockType) and returns the names of attributes that hold
	 * actual translatable content - filtering out config / metadata strings
	 * like className, anchor, alignment, etc.
	 *
	 * Used as a 2nd-priority fallback in textAttrChain() so blocks that
	 * follow Gutenberg conventions get auto-discovered without needing
	 * either a manual entry in the per_block map or a JS hook registration.
	 *
	 * Result memoized per blockName because the registered schema is
	 * effectively immutable for the lifetime of the page.
	 *
	 * Inclusion rules (conservative — false positives on text translation
	 * are worse than false negatives):
	 *   - type: 'rich-text' (RichTextData)               → include
	 *   - type: 'string' AND source: 'html'              → include (HTML body)
	 *   - type: 'string' AND source: 'rich-text'         → include
	 *   - type: 'string' AND source: 'children'          → include (legacy rich text)
	 *   - type: 'string' AND source: 'attribute'         → SKIP (URL / class / id)
	 *   - type: 'string' with no source                  → SKIP unless name suggests text
	 *   - type: anything else                            → SKIP
	 *
	 * Names matching IGNORE_NAMES are always skipped even if the type/source
	 * looks right (covers a few common stored-string cases).
	 */
	var SCHEMA_DISCOVERY_CACHE = {};
	var IGNORE_ATTR_NAMES = /^(class|className|anchor|id|url|href|src|target|rel|tagName|level|align|orientation|direction|mode|style|color|backgroundColor|fontSize|fontFamily|borderRadius|borderColor|width|height|gap)$/i;

	// Attribute NAMES that are translatable user-facing text even when the
	// schema marks them `source: 'attribute'` (i.e. they map to an HTML
	// attribute rather than HTML body content).
	//
	// Rationale: `source: 'attribute'` covers two very different cases —
	//   - URL-like values (href, src, target, rel) → must NOT translate
	//   - User-visible accessibility text (alt, title, placeholder,
	//     aria-label, caption summary) → SHOULD translate
	// The distinction is the attribute NAME, not the source. We allowlist
	// the second group; everything else with source:'attribute' is skipped
	// by default, preserving the conservative "don't translate URLs" rule.
	var TRANSLATABLE_ATTR_NAMES = /^(alt|title|placeholder|ariaLabel|aria-label|description|summary|tooltip)$/i;

	/**
	 * Inspect an attributes-schema object (the raw shape from registerBlockType)
	 * and return the names of attributes that hold real translatable content.
	 *
	 * Inclusion rules - conservative; false positives on text translation are
	 * worse than false negatives:
	 *   - type: 'rich-text'                              → include (RichTextData)
	 *   - type: 'string' AND source: 'html'              → include (HTML body)
	 *   - type: 'string' AND source: 'rich-text'         → include
	 *   - type: 'string' AND source: 'children'          → include (legacy rich text)
	 *   - type: 'string' AND source: 'attribute'         → skip (URL / class / id)
	 *   - type: 'string' with no source                  → skip (stored config string)
	 *   - any other type                                 → skip
	 *
	 * Plus a small name-based blocklist (className, anchor, alignment, …)
	 * that catches a few known-config strings that CMS authors might still
	 * mark as `source: 'html'` by mistake.
	 *
	 * @param {object} attributes - attribute schema map (`name -> def`)
	 * @return {string[]} attribute names containing translatable text
	 */
	function findTextAttrsInSchema( attributes ) {
		var found = [];

		if ( ! attributes || typeof attributes !== 'object' ) { return found; }

		for ( var attr in attributes ) {
			if ( ! Object.prototype.hasOwnProperty.call( attributes, attr ) ) { continue; }
			if ( IGNORE_ATTR_NAMES.test( attr ) ) { continue; }

			var def = attributes[ attr ];
			if ( ! def || typeof def !== 'object' ) { continue; }

			var include = false;

			if ( def.type === 'rich-text' ) {
				include = true;
			} else if ( def.type === 'string' ) {
				if ( def.source === 'html' || def.source === 'rich-text' || def.source === 'children' ) {
					include = true;
				} else if ( def.source === 'attribute' && TRANSLATABLE_ATTR_NAMES.test( attr ) ) {
					// Allowlisted accessibility attributes: alt, title,
					// placeholder, aria-label, etc. are user-visible text
					// even though they map to HTML attributes.
					include = true;
				}
			}

			if ( include ) {
				found.push( attr );
			}
		}

		return found;
	}

	/**
	 * Memoized variant that looks the block up in the WP registry by name.
	 * Used at runtime AFTER block registration is done. For the
	 * blocks.registerBlockType filter (where the block isn't yet registered),
	 * call findTextAttrsInSchema(settings.attributes) directly instead.
	 *
	 * Empty results are NOT cached: third-party blocks sometimes register
	 * via `domReady`, after our first lookup. Caching an empty result would
	 * permanently mark a late-loaded block as non-translatable until the
	 * user reloads. Caching only positive hits means the next inspection
	 * after registration completes will pick the block up.
	 */
	function discoverTextAttrsFromSchema( blockName ) {
		if ( SCHEMA_DISCOVERY_CACHE[ blockName ] !== undefined ) {
			return SCHEMA_DISCOVERY_CACHE[ blockName ];
		}

		var blockType = ( wp.blocks && typeof wp.blocks.getBlockType === 'function' )
			? wp.blocks.getBlockType( blockName )
			: null;

		var found = findTextAttrsInSchema( blockType ? blockType.attributes : null );

		if ( found.length > 0 ) {
			SCHEMA_DISCOVERY_CACHE[ blockName ] = found;
		}

		return found;
	}

	/**
	 * Whether this block type is eligible for the Translate toolbar.
	 *
	 * Combines the explicit allowlist (cfg.supportedBlocks - the safe known
	 * set) with schema-driven discovery (any block whose registered attribute
	 * schema declares at least one rich-text or html-sourced text attribute).
	 *
	 * The optional `attributes` parameter lets the blocks.registerBlockType
	 * filter pass settings.attributes directly - at that lifecycle moment,
	 * `getBlockType(name)` returns null because the block isn't yet
	 * registered, but the caller already holds the attribute schema.
	 *
	 * @param {string} blockName
	 * @param {object|undefined} attributes - optional attribute schema
	 * @return {boolean}
	 */
	function isTranslatableBlock( blockName, attributes ) {
		if ( SUPPORTED_BLOCKS.indexOf( blockName ) !== -1 ) { return true; }

		if ( attributes ) {
			return findTextAttrsInSchema( attributes ).length > 0;
		}

		return discoverTextAttrsFromSchema( blockName ).length > 0;
	}

	function textAttrChain( blockName ) {
		// Block-specific attribute order. List ALL attributes that may carry
		// translatable text so getBlockText can pick the populated one. The
		// "longest wins" rule below ensures we don't translate an empty
		// `caption` over a populated `alt`, etc.
		//
		// Cover / media-text / columns / group store their text in INNER
		// BLOCKS, not top-level attributes - they're intentionally absent
		// from this map so getBlockText returns empty and the user gets the
		// "no text to translate" notice instead of a write to nowhere. Inner-
		// block translation is a separate, recursive concern.
		//
		// MUST stay in sync with BlockTranslateController::text_attr_chain()
		// in src/Api/BlockTranslateController.php — both sides walk the same
		// per-block chain server- and client-side. Drift = sibling fill-from-
		// source picking different attributes between client hint and server
		// resolution. tests/test-attr-chain-parity.php asserts the two match.
		var per_block = {
			'core/quote':         [ 'value' ],
			'core/pullquote':     [ 'value', 'citation' ],
			'core/button':        [ 'text' ],
			'core/details':       [ 'summary' ],
			'core/image':         [ 'caption', 'alt', 'title' ],
			'core/embed':         [ 'caption' ],
			'core/audio':         [ 'caption' ],
			'core/video':         [ 'caption' ],
			'core/post-excerpt':  [ 'excerpt' ],
		};

		var chain;

		if ( per_block[ blockName ] ) {
			// Manual override wins - explicit, predictable, fastest.
			chain = per_block[ blockName ];
		} else {
			// 2nd priority: schema introspection. Picks up custom blocks
			// that registered text attrs without needing a code change here.
			var fromSchema = discoverTextAttrsFromSchema( blockName );

			if ( fromSchema.length ) {
				chain = fromSchema;
			} else {
				// Fallback: legacy guesses for blocks with no schema.
				chain = [ 'content', 'text', 'value' ];
			}
		}

		if ( wp.hooks && typeof wp.hooks.applyFilters === 'function' ) {
			chain = wp.hooks.applyFilters( 'perflocale.blockToolbar.textAttrs', chain, blockName );
		}

		return Array.isArray( chain ) && chain.length ? chain : [ 'content' ];
	}

	/**
	 * Coerce any block-attribute value to a plain HTML string.
	 *
	 * Modern Gutenberg (WP 6.4+) types `core/paragraph` and `core/heading`
	 * `content` as `rich-text`, so the runtime value is a `RichTextData`
	 * instance - not a string. The original `typeof val === 'string'`
	 * guard would silently treat that as "no text", which is the bug the
	 * user reported with paragraphs.
	 *
	 * We try (in order) the documented coercion methods before falling
	 * back to String(); the type-narrowing with `typeof` first means we
	 * never call methods on null/undefined.
	 */
	function attrToString( val ) {
		if ( val === null || val === undefined ) { return ''; }
		if ( typeof val === 'string' ) { return val; }

		// RichTextData (WP 6.4+) and similar value-objects.
		if ( typeof val === 'object' ) {
			if ( typeof val.toHTMLString === 'function' ) { return val.toHTMLString(); }
			if ( typeof val.toString === 'function' && val.toString !== Object.prototype.toString ) {
				var s = val.toString();
				if ( typeof s === 'string' && s !== '[object Object]' ) { return s; }
			}
			if ( typeof val.text === 'string' ) { return val.text; }
			if ( typeof val.html === 'string' ) { return val.html; }
		}

		return '';
	}

	function getBlockText( block ) {
		if ( ! block || ! block.attributes ) { return ''; }

		var chain = textAttrChain( block.name );
		var longest = '';
		var longestAttr = chain[0] || 'content';

		// Pick the attribute with the most text on this specific block.
		// Prevents picking an empty `caption` over a populated `alt` on
		// an image, etc. The first non-empty wins ties.
		for ( var i = 0; i < chain.length; i++ ) {
			var s = attrToString( block.attributes[ chain[ i ] ] );

			if ( s.length > longest.length ) {
				longest = s;
				longestAttr = chain[ i ];
			}
		}

		// Memoize the chosen attribute on the block reference so setBlockText
		// writes to the SAME attribute we read from. The translation flow is
		// short-lived (one click), so adding a non-enumerable hint is fine
		// and avoids a second pass through the chain.
		try { Object.defineProperty( block, '_pftWriteAttr', { value: longestAttr, configurable: true } ); }
		catch ( _ ) { /* frozen blocks just re-resolve in setBlockText */ }

		return longest;
	}

	function setBlockText( clientId, block, newText ) {
		// Prefer the attribute getBlockText picked from the chain; if the
		// hint is missing (frozen block in step above), re-resolve to the
		// first chain entry so we still write somewhere reasonable.
		var attr = ( block && block._pftWriteAttr )
			? block._pftWriteAttr
			: ( textAttrChain( block.name )[0] || 'content' );

		var update = {};
		update[ attr ] = newText;
		wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( clientId, update );
	}

	/**
	 * Recursively walk a block tree and return every translatable leaf.
	 *
	 * The "leaf" model: each item in the returned array represents ONE
	 * attribute write (clientId + attr + text). Container blocks like
	 * `core/group` typically contribute zero leaves themselves but their
	 * children contribute leaves at their own depth.
	 *
	 * Used by:
	 *  - 1.3: multi-block batch translate (when the user has multiple
	 *    blocks selected, each contributes its own leaves to the batch).
	 *  - 2.1: "Translate this section" on container blocks - walk the
	 *    container's clientId and translate everything under it.
	 *  - 2.2: "Translate entire post" - walk all top-level blocks.
	 *
	 * Honors the `perflocaleSkipTranslation` attribute: a parent block
	 * marked as skipped still has its children walked (the user may want
	 * the wrapper preserved but children translated). A LEAF block marked
	 * as skipped is excluded from the result.
	 *
	 * Empty / whitespace-only text is filtered out so the batch only
	 * carries meaningful content.
	 *
	 * @param {string|undefined} rootClientId - undefined → all top-level blocks
	 * @return {Array<{clientId: string, blockName: string, text: string, writeAttr: string}>}
	 */
	function collectTranslatableBlocks( rootClientId ) {
		var blockSelect = wp.data.select( 'core/block-editor' );

		if ( ! blockSelect ) { return []; }

		var collected = [];

		function walk( block ) {
			if ( ! block || ! block.name ) { return; }

			var skipped = block.attributes && block.attributes[ SKIP_ATTR ] === true;

			// Only include the block ITSELF as a leaf if it's not skipped
			// AND is translatable (has discoverable text attributes). Inner
			// blocks are walked unconditionally - the user may have skipped
			// only the wrapper.
			if ( ! skipped && isTranslatableBlock( block.name ) ) {
				var text = getBlockText( block );

				if ( text && typeof text === 'string' && text.trim() !== '' ) {
					collected.push( {
						clientId:  block.clientId,
						blockName: block.name,
						text:      text,
						writeAttr: block._pftWriteAttr || ( textAttrChain( block.name )[0] || 'content' )
					} );
				}
			}

			if ( block.innerBlocks && block.innerBlocks.length ) {
				for ( var i = 0; i < block.innerBlocks.length; i++ ) {
					walk( block.innerBlocks[ i ] );
				}
			}
		}

		if ( rootClientId ) {
			var root = blockSelect.getBlock( rootClientId );
			if ( root ) { walk( root ); }
		} else {
			var topLevel = blockSelect.getBlocks();
			for ( var i = 0; i < topLevel.length; i++ ) {
				walk( topLevel[ i ] );
			}
		}

		return collected;
	}

	/**
	 * Apply a batch of translations back to the editor in one undo step.
	 *
	 * Wraps the per-leaf updateBlockAttributes calls in a single edit
	 * transaction so the user's Cmd+Z reverses the whole batch instead of
	 * each individual block change. Falls back to per-leaf dispatch when
	 * the editor doesn't expose the transaction API (older WP versions).
	 *
	 * @param {Array<{clientId: string, writeAttr: string}>} leaves - same shape collectTranslatableBlocks returns
	 * @param {Array<string>} translations - parallel array; translations[i] applied to leaves[i]
	 */
	function applyBatchTranslations( leaves, translations ) {
		var dispatch = wp.data.dispatch( 'core/block-editor' );
		var select = wp.data.select( 'core/block-editor' );
		var applied = 0;

		// __unstableMarkNextChangeAsNotPersistent / mergeUndoableChanges
		// aren't part of the public API. Writes issued back-to-back in one
		// tick coalesce in the editor's undo window; exact granularity is
		// Gutenberg's per-block undo semantics, not a hard one-step
		// guarantee. Blocks removed while a request was in flight are
		// skipped (a write to a gone clientId would be a silent no-op
		// anyway) so the returned count reflects real writes.
		for ( var i = 0; i < leaves.length; i++ ) {
			if ( typeof translations[ i ] !== 'string' ) { continue; }
			// An empty translation for a leaf that had text is a provider
			// failure for that entry — writing it would blank the block's
			// content. Skip it; the applied count (and the toast built from
			// it) then reflects only real writes.
			if ( translations[ i ] === '' ) { continue; }
			if ( select && ! select.getBlock( leaves[ i ].clientId ) ) { continue; }

			var update = {};
			update[ leaves[ i ].writeAttr ] = translations[ i ];
			dispatch.updateBlockAttributes( leaves[ i ].clientId, update );
			applied++;
		}

		return applied;
	}

	function isSkipMarked( attrs ) {
		return !! ( attrs && attrs[ SKIP_ATTR ] === true );
	}

	/**
	 * Toggle the "do not translate" state via the canonical block
	 * attribute. Does NOT touch the user-facing `className` field -
	 * that stays under the editor's sole control.
	 */
	function toggleSkipAttribute( block, clientId ) {
		var update = {};
		update[ SKIP_ATTR ] = ! isSkipMarked( block.attributes || {} );
		wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( clientId, update );
	}

	function notice( message, type, id ) {
		if ( wp.data.dispatch( 'core/notices' ) ) {
			var options = { type: 'snackbar', isDismissible: true };

			// A stable id makes core/notices REPLACE the previous notice with
			// the same id instead of stacking a new snackbar — used by the
			// chunked batch translator's progress updates.
			if ( id ) { options.id = id; }

			wp.data.dispatch( 'core/notices' ).createNotice(
				type || 'success',
				message,
				options
			);
		}
	}

	// ---------------------------------------------------------------
	// Block position path (for sibling-aware fill-from-source)
	// ---------------------------------------------------------------

	/**
	 * Compute the position path of a block in the editor's block tree.
	 * Walks the tree top-down looking for the matching clientId; returns
	 * an array of integer indices (e.g. [3, 1, 2] = top-level block 3,
	 * then inner block 1, then inner block 2).
	 *
	 * Used to find the corresponding block in the source-language
	 * sibling: same path → corresponding block (assuming the sibling
	 * was created from source via auto-stub or copy and not heavily
	 * restructured). Server-side falls back to a 404 if the path is
	 * out of range in the source post.
	 *
	 * @param {string} clientId
	 * @return {number[]|null} index path, or null if not found
	 */
	function computeBlockPath( clientId ) {
		var blockSelect = wp.data.select( 'core/block-editor' );
		if ( ! blockSelect ) { return null; }

		var topBlocks = blockSelect.getBlocks();

		function descend( blocks, path ) {
			for ( var i = 0; i < blocks.length; i++ ) {
				var block = blocks[ i ];

				if ( block.clientId === clientId ) {
					return path.concat( i );
				}

				if ( block.innerBlocks && block.innerBlocks.length ) {
					var hit = descend( block.innerBlocks, path.concat( i ) );
					if ( hit ) { return hit; }
				}
			}
			return null;
		}

		return descend( topBlocks, [] );
	}

	/**
	 * Sibling-aware fill: fetch the corresponding block from the source
	 * post, MT-translate it from source-lang to current-post-lang, write
	 * the result back to this block.
	 *
	 * Used by the per-block toolbar action when IS_SIBLING is true. The
	 * server resolves the source post from the post's translation group
	 * and walks its block tree to the same position path.
	 *
	 * @param {string}        clientId
	 * @param {function|null} onClose
	 */
	function fillFromSource( clientId, onClose ) {
		if ( ! TARGET_POST_ID || ! SOURCE_POST_ID || ! SOURCE_LANG ) {
			notice( __( 'No source post detected for this sibling.', 'perflocale' ), 'error' );
			onClose && onClose();
			return;
		}

		var path = computeBlockPath( clientId );

		if ( ! path ) {
			notice( __( 'Could not locate this block in the editor tree.', 'perflocale' ), 'error' );
			onClose && onClose();
			return;
		}

		// Read the real block from the editor store so the write-attr hint
		// resolves against the actual attributes (the toolbar's `props.attributes`
		// is a shallow copy without the `_pftWriteAttr` non-enumerable hint).
		// getBlockText() populates the hint as a side-effect.
		var realBlock = wp.data.select( 'core/block-editor' ).getBlock( clientId );
		if ( ! realBlock ) {
			notice( __( 'Could not read the block from the editor.', 'perflocale' ), 'error' );
			onClose && onClose();
			return;
		}
		getBlockText( realBlock );

		// targetLang = the post's own language (this is a sibling, so
		// POST_SOURCE_LANG is the sibling's lang, NOT the source).
		var targetLang = POST_SOURCE_LANG;

		notice( i18n.translating || __( 'Translating…', 'perflocale' ), 'info' );

		// Hint the server which attribute to read from the source block.
		// The same chain runs server-side as a fallback, but passing the
		// hint avoids picking the wrong attribute when both `caption` and
		// `alt` are populated and the user clicked through to a specific
		// edit affordance.
		var hintAttr = realBlock._pftWriteAttr || '';

		wp.apiFetch( {
			path: '/perflocale/v1/block-translate/from-source',
			method: 'POST',
			data: {
				target_post_id: TARGET_POST_ID,
				block_path:     path,
				target_lang:    targetLang,
				source_attr:    hintAttr
			}
		} )
			.then( function ( response ) {
				var data = ( response && response.data && typeof response.data === 'object' ) ? response.data : response;

				if ( data && typeof data.translated === 'string' && data.translated !== '' ) {
					// Non-empty check: the server rejects empty provider results,
					// but never write '' over block content even if one slips
					// through — the else branch below surfaces it as a failure.
					// Prefer the server's resolved source_attr — it ran the same
					// chain on the actual source block and knows which attribute
					// the text was extracted from. Falls through to the local
					// hint if missing (older server / edge cases).
					var writeAttr = ( typeof data.source_attr === 'string' && data.source_attr !== '' && data.source_attr !== 'innerHTML' )
						? data.source_attr
						: ( realBlock._pftWriteAttr || ( textAttrChain( realBlock.name )[0] || 'content' ) );

					var update = {};
					update[ writeAttr ] = data.translated;
					wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( clientId, update );

					var srcLabel = '';
					if ( data.source === 'tm' ) {
						srcLabel = __( 'translation memory', 'perflocale' );
					} else if ( typeof data.provider === 'string' && data.provider !== '' ) {
						srcLabel = PROVIDER_LABELS[ data.provider ] || data.provider;
					} else if ( providerLabel() ) {
						srcLabel = providerLabel();
					}

					var fullToast = sprintf(
						/* translators: %s: target language label e.g. "French (FR)" */
						__( 'Filled in from source — %s', 'perflocale' ),
						formatLangLabel( targetLang )
					);
					if ( srcLabel ) { fullToast += ' · ' + srcLabel; }

					notice( fullToast, 'success' );
				} else {
					notice( __( 'Source-fill returned unexpected data.', 'perflocale' ), 'error' );
				}
			} )
			.catch( function ( err ) {
				var msg = ( err && err.message ) || __( 'Source-fill failed.', 'perflocale' );
				notice( msg, 'error' );
			} )
			.finally( function () { onClose && onClose(); } );
	}

	// ---------------------------------------------------------------
	// Action: translate with MT (submenu)
	// ---------------------------------------------------------------

	function translateWithMt( block, clientId, targetLang, onClose ) {
		// Prefer the edited post's actual language over the admin router
		// language - editing a BG sibling in an EN admin session must
		// still send source_lang=bg.
		var sourceLang = POST_SOURCE_LANG || CURRENT_LANG || ( LANGUAGES[0] && LANGUAGES[0].slug ) || 'en';

		if ( sourceLang === targetLang ) {
			notice( __( 'Source and target languages are the same.', 'perflocale' ), 'warning' );
			onClose && onClose();
			return;
		}

		var text = getBlockText( block );

		if ( ! text ) {
			notice( __( 'Block has no text to translate.', 'perflocale' ), 'warning' );
			onClose && onClose();
			return;
		}

		notice( i18n.translating || __( 'Translating…', 'perflocale' ), 'info' );

		wp.apiFetch( {
			path: '/perflocale/v1/block-translate',
			method: 'POST',
			data: { text: text, source_lang: sourceLang, target_lang: targetLang }
		} )
			.then( function ( response ) {
				// BlockTranslateController emits the body via WP_REST_Response
				// so apiFetch resolves with `{translated, provider}` at top level.
				// The `response.data` fallback handles any future shape that
				// wraps the data (e.g. wp_send_json_success, middleware) without
				// re-introducing the bug where the translated string is dropped
				// because the JS looked for response.data.translated when the
				// actual key is response.translated.
				var data = ( response && response.data && typeof response.data === 'object' ) ? response.data : response;

				if ( data && typeof data.translated === 'string' ) {
					setBlockText( clientId, block, data.translated );

					// Source label: "DeepL", "translation memory", etc.
					// `data.source === 'tm'` is set by the controller when a
					// pre-flight TM lookup returned an exact match
					// 2.3) — surface that to the user so they know the
					// translation was free vs MT-quota-consuming.
					var srcLabel = '';
					if ( data.source === 'tm' ) {
						srcLabel = __( 'translation memory', 'perflocale' );
					} else if ( typeof data.provider === 'string' && data.provider !== '' ) {
						srcLabel = PROVIDER_LABELS[ data.provider ] || data.provider;
					} else if ( providerLabel() ) {
						srcLabel = providerLabel();
					}

					var fullToast = sprintf(
						i18n.translatedTo || __( 'Translated to %s', 'perflocale' ),
						formatLangLabel( targetLang )
					);
					if ( srcLabel ) {
						fullToast += ' · ' + srcLabel;
					}

					notice( fullToast, 'success' );
				} else {
					notice( __( 'Translation returned unexpected data.', 'perflocale' ), 'error' );
				}
			} )
			.catch( function ( err ) {
				var msg = ( err && err.message ) || __( 'Translation failed.', 'perflocale' );
				notice( msg, 'error' );
			} )
			.finally( function () { onClose && onClose(); } );
	}

	/**
	 * Batch-translate a list of block leaves (from collectTranslatableBlocks)
	 * in ONE provider round-trip via the /block-translate endpoint's
	 * `texts` array param. Used by:
	 *   - 1.3: multi-block selection translate
	 *   - 2.1: "Translate this section" on container blocks
	 *   - 2.2: "Translate entire post" sidebar button
	 *
	 * On success, applies all translations atomically (best-effort: same
	 * Gutenberg dispatch tick → coalesces into one undo step in modern WP).
	 *
	 * @param {Array} leaves - output of collectTranslatableBlocks()
	 * @param {string} targetLang - target language slug
	 * @param {function|null} onClose - optional menu-close callback
	 */
	// The batch endpoint caps one request at 500 entries
	// (BlockTranslateController::MAX_BATCH_ENTRIES) and ~200k aggregate
	// bytes. Chunk comfortably below BOTH so a legitimately huge post (big
	// table / FAQ / glossary with more than 500 text leaves, or long-form
	// multibyte text) translates in sequential passes instead of
	// hard-failing with batch_too_many / batch_too_long.
	var BATCH_CHUNK_SIZE = 400;
	var BATCH_CHUNK_BYTES = 150000;

	var utf8Encoder = ( typeof TextEncoder !== 'undefined' ) ? new TextEncoder() : null;

	function utf8Length( s ) {
		// Fallback doubles the UTF-16 unit count — a safe overestimate that
		// only makes chunks smaller, never over the server cap.
		return utf8Encoder ? utf8Encoder.encode( s ).length : s.length * 2;
	}

	function chunkLeaves( leaves ) {
		var out = [];
		var current = [];
		var bytes = 0;

		for ( var i = 0; i < leaves.length; i++ ) {
			var len = utf8Length( leaves[ i ].text || '' );

			if ( current.length > 0 && ( current.length >= BATCH_CHUNK_SIZE || bytes + len > BATCH_CHUNK_BYTES ) ) {
				out.push( current );
				current = [];
				bytes = 0;
			}

			current.push( leaves[ i ] );
			bytes += len;
		}

		if ( current.length > 0 ) { out.push( current ); }

		return out;
	}

	// One batch run at a time: the dropdown stays open while a chain runs,
	// and a second click would re-collect ALREADY-TRANSLATED text and send
	// it back through MT (double-translated garbage, double quota spend).
	var mtBatchInFlight = false;

	function translateBlocksWithMt( leaves, targetLang, onClose ) {
		if ( ! Array.isArray( leaves ) || leaves.length === 0 ) {
			notice( __( 'No translatable text found in this section.', 'perflocale' ), 'warning' );
			onClose && onClose();
			return;
		}

		var sourceLang = POST_SOURCE_LANG || CURRENT_LANG || ( LANGUAGES[0] && LANGUAGES[0].slug ) || 'en';

		if ( sourceLang === targetLang ) {
			notice( __( 'Source and target languages are the same.', 'perflocale' ), 'warning' );
			onClose && onClose();
			return;
		}

		if ( mtBatchInFlight ) {
			notice( __( 'A translation batch is already running — wait for it to finish.', 'perflocale' ), 'warning' );
			onClose && onClose();
			return;
		}
		mtBatchInFlight = true;

		var chunks = chunkLeaves( leaves );
		var total = leaves.length;
		var appliedCount = 0;
		var doneCount = 0;
		var lastData = null;
		var PROGRESS_ID = 'perflocale-batch-progress';

		if ( chunks.length === 1 ) {
			notice(
				sprintf(
					/* translators: %d: number of blocks being translated */
					__( 'Translating %d blocks…', 'perflocale' ),
					total
				),
				'info',
				PROGRESS_ID
			);
		}

		// Sequential chunk chain, each pass applied to the editor as soon as
		// it returns, so a failure mid-way keeps the work already done and
		// says so instead of discarding everything. (Undo granularity
		// follows the editor's own per-block semantics.)
		var chain = Promise.resolve();

		chunks.forEach( function ( pass ) {
			chain = chain.then( function () {
				if ( chunks.length > 1 ) {
					notice(
						sprintf(
							/* translators: 1: first block number in this pass, 2: last block number, 3: total blocks */
							__( 'Translating blocks %1$d–%2$d of %3$d…', 'perflocale' ),
							doneCount + 1,
							doneCount + pass.length,
							total
						),
						'info',
						PROGRESS_ID
					);
				}

				return wp.apiFetch( {
					path: '/perflocale/v1/block-translate',
					method: 'POST',
					data: {
						texts: pass.map( function ( l ) { return l.text; } ),
						source_lang: sourceLang,
						target_lang: targetLang
					}
				} ).then( function ( response ) {
					var data = ( response && response.data && typeof response.data === 'object' ) ? response.data : response;

					if ( ! data || ! Array.isArray( data.translated ) ) {
						throw new Error( __( 'Batch translation returned unexpected data.', 'perflocale' ) );
					}

					if ( data.translated.length !== pass.length ) {
						throw new Error( sprintf(
							/* translators: 1: expected count, 2: received count */
							__( 'Batch size mismatch: expected %1$d, got %2$d.', 'perflocale' ),
							pass.length,
							data.translated.length
						) );
					}

					appliedCount += applyBatchTranslations( pass, data.translated );
					doneCount += pass.length;
					lastData = data;
				} );
			} );
		} );

		chain
			.then( function () {
				var srcLabel = '';
				if ( lastData && lastData.source === 'tm' ) {
					srcLabel = __( 'translation memory', 'perflocale' );
				} else if ( lastData && typeof lastData.provider === 'string' && lastData.provider !== '' ) {
					srcLabel = PROVIDER_LABELS[ lastData.provider ] || lastData.provider;
				} else if ( providerLabel() ) {
					srcLabel = providerLabel();
				}

				var fullToast = sprintf(
					/* translators: 1: number of blocks, 2: target language label */
					__( 'Translated %1$d blocks to %2$s', 'perflocale' ),
					appliedCount,
					formatLangLabel( targetLang )
				);
				if ( srcLabel ) { fullToast += ' · ' + srcLabel; }

				notice( fullToast, 'success', PROGRESS_ID );
			} )
			.catch( function ( err ) {
				var msg = ( err && err.message ) || __( 'Batch translation failed.', 'perflocale' );

				if ( appliedCount > 0 ) {
					msg += ' ' + sprintf(
						/* translators: 1: blocks already translated, 2: total blocks */
						__( '(%1$d of %2$d blocks were already translated and kept.)', 'perflocale' ),
						appliedCount,
						total
					);
				}

				notice( msg, 'error', PROGRESS_ID );
			} )
			.finally( function () {
				mtBatchInFlight = false;
				onClose && onClose();
			} );
	}

	// ---------------------------------------------------------------
	// Action: wrap in "Show If Language"
	// ---------------------------------------------------------------

	function WrapInConditionModal( props ) {
		var clientId = props.clientId;
		var onClose = props.onClose;
		var state = useState( {} );
		var selected = state[0];
		var setSelected = state[1];
		var invertState = useState( false );
		var invert = invertState[0];
		var setInvert = invertState[1];

		function toggle( slug, checked ) {
			var next = Object.assign( {}, selected );
			if ( checked ) {
				next[ slug ] = true;
			} else {
				delete next[ slug ];
			}
			setSelected( next );
		}

		function apply() {
			var langs = Object.keys( selected );

			if ( langs.length === 0 ) {
				notice( __( 'Pick at least one language.', 'perflocale' ), 'warning' );
				return;
			}

			// Fetch the real block instance from the editor store -
			// cloneBlock() needs a full block object (with innerBlocks,
			// isValid, etc.), not the synthetic `{ name, attributes }`
			// shim we pass into the modal for display.
			var realBlock = wp.data.select( 'core/block-editor' ).getBlock( clientId );

			if ( ! realBlock ) {
				notice( __( 'Could not read the selected block.', 'perflocale' ), 'error' );
				return;
			}

			var wrapper = wp.blocks.createBlock(
				'perflocale/if-language',
				{ languages: langs, invert: invert },
				[ wp.blocks.cloneBlock( realBlock ) ]
			);

			wp.data.dispatch( 'core/block-editor' ).replaceBlock( clientId, wrapper );
			notice( __( 'Wrapped in "Show If Language".', 'perflocale' ), 'success' );
			onClose();
		}

		var checkboxes = LANGUAGES.map( function ( lang ) {
			return el( CheckboxControl, {
				key: lang.slug,
				label: ( lang.name || lang.slug ) + ' (' + ( lang.bcp47 || String( lang.slug ).toUpperCase() ) + ')',
				checked: !! selected[ lang.slug ],
				onChange: function ( c ) { toggle( lang.slug, c ); }
			} );
		} );

		return el(
			Modal,
			{
				title: i18n.wrapTitle || __( 'Show only for certain languages', 'perflocale' ),
				onRequestClose: onClose,
				style: { maxWidth: '440px' }
			},
			LANGUAGES.length === 0
				? el( 'p', null, __( 'No active languages configured.', 'perflocale' ) )
				: el( 'div', null, checkboxes ),
			el( 'div', { style: { marginTop: '12px', paddingTop: '12px', borderTop: '1px solid #e0e0e0' } },
				el( ToggleControl, {
					label: __( 'Invert - show everywhere EXCEPT these languages', 'perflocale' ),
					checked: invert,
					onChange: function ( v ) { setInvert( !! v ); }
				} )
			),
			el( 'div', { style: { marginTop: '16px', display: 'flex', justifyContent: 'flex-end', gap: '8px' } },
				el( Button, { variant: 'tertiary', onClick: onClose }, __( 'Cancel', 'perflocale' ) ),
				el( Button, { variant: 'primary', onClick: apply, disabled: LANGUAGES.length === 0 },
					__( 'Apply', 'perflocale' )
				)
			)
		);
	}

	// ---------------------------------------------------------------
	// Action: TM suggestions modal
	// ---------------------------------------------------------------

	// ---------------------------------------------------------------
	// Toolbar dropdown - the HOC that wraps every supported block
	// ---------------------------------------------------------------

	var withPerfLocaleToolbar = createHOC( function ( BlockEdit ) {
		return function ( props ) {
			if ( ! isTranslatableBlock( props.name ) ) {
				return el( BlockEdit, props );
			}

			if ( ! props.isSelected ) {
				return el( BlockEdit, props );
			}

			var wrapOpen = useState( false );
			var showWrap = wrapOpen[0];
			var setShowWrap = wrapOpen[1];


			// Hide whichever language we'll be translating FROM - it makes
			// no sense to offer "Translate to <same>".
			var effectiveSource = POST_SOURCE_LANG || CURRENT_LANG;
			var mtSubmenu = LANGUAGES.filter( function ( lang ) {
				return lang.slug !== effectiveSource;
			} );

			var isSkipped = isSkipMarked( props.attributes );

			function mkMenuItems( onClose ) {
				var items = [];

				if ( MT_READY && mtSubmenu.length > 0 ) {
					var providerSuffix = providerLabel() ? ' · ' + providerLabel() : '';

					// Sibling-aware menu. When the post being
					// edited is a translation (not the source), replace the
					// multi-target dropdown with a SINGLE "Fill in from
					// <source-lang> source" action. This matches the user's
					// mental model: "I'm in the FR sibling — translating to
					// DE here doesn't make sense; what I want is to pull
					// the corresponding EN paragraph and translate it to FR".
					//
					// Source posts (post.language === default) keep the
					// original multi-target dropdown — that's where the
					// fan-out / preview workflow lives.
					if ( IS_SIBLING && SOURCE_POST_ID > 0 ) {
						var sourceLangLabel = formatLangLabel( SOURCE_LANG );

						items.push( el( MenuItem, {
							key: 'mt-from-source',
							icon: 'translation',
							onClick: function () {
								fillFromSource( props.clientId, onClose );
							}
						}, sprintf(
							/* translators: %s: source language label e.g. "English (EN)" */
							__( 'Fill in from %s source', 'perflocale' ),
							sourceLangLabel
						) + providerSuffix ) );
					} else {
						// SOURCE post (or sibling without a resolvable source) →
						// keep the original multi-target dropdown so authors can
						// fan out translations / preview in place.
						//
						// Click handler uses the recursive walker + batch endpoint
						// so the same item works for:
						//   - leaf blocks (paragraph, heading) → 1 leaf, single MT call
						//   - container blocks (group, columns, cover, quote with
						//     inner content) → walks children, ONE batch round-trip
						//   - blocks with multi-attribute text (image alt + caption)
						//     → translates the longest, picked by getBlockText
						var translatableLeaves = collectTranslatableBlocks( props.clientId );
						var leafCount = translatableLeaves.length;
						var leafSuffix = leafCount > 1
							? ' · ' + sprintf(
								/* translators: %d: number of inner blocks that will be translated */
								__( '%d blocks', 'perflocale' ),
								leafCount
							)
							: '';

						mtSubmenu.forEach( function ( lang ) {
							items.push( el( MenuItem, {
								key: 'mt-' + lang.slug,
								icon: 'translation',
								onClick: function () {
									// Re-collect at click-time in case the editor
									// state changed (block added/removed) between
									// menu-render and click. Cheap — ~microseconds
									// even for large posts.
									translateBlocksWithMt(
										collectTranslatableBlocks( props.clientId ),
										lang.slug,
										onClose
									);
								}
							}, sprintf(
								/* translators: %s: target language name (or BCP 47 tag fallback, e.g. "en-GB") */
								__( 'Translate to %s', 'perflocale' ),
								lang.name || lang.bcp47 || lang.slug
							) + providerSuffix + leafSuffix ) );
						} );
					}
				} else if ( ! MT_READY && MT_SETTINGS_URL ) {
					// MT not ready - show a single actionable setup link
					// rather than a confusing disabled entry. Drops the
					// user right at the MT settings tab.
					items.push( el( MenuItem, {
						key: 'mt-setup',
						icon: 'translation',
						onClick: function () {
							onClose();
							window.location.href = MT_SETTINGS_URL;
						}
					}, cfg.i18n && cfg.i18n.setUpMt ? cfg.i18n.setUpMt : __( 'Set up machine translation…', 'perflocale' ) ) );
				}
				// If MT_READY but no target languages (mtSubmenu empty),
				// render nothing for this group - clean UX, no clutter.

				if ( items.length === 0 ) {
					return null;
				}

				return el( MenuGroup, null, items );
			}

			function mkToolsGroup( onClose ) {
				return el( MenuGroup, null,
					el( MenuItem, {
						icon: 'filter',
						onClick: function () { onClose(); setShowWrap( true ); }
					}, __( 'Wrap in "Show If Language"…', 'perflocale' ) ),
					el( MenuItem, {
						icon: isSkipped ? 'yes-alt' : 'no-alt',
						onClick: function () {
							toggleSkipAttribute(
								{ name: props.name, attributes: props.attributes },
								props.clientId
							);
							onClose();
						}
					}, isSkipped
						? __( 'Remove "Do not translate" marker', 'perflocale' )
						: __( 'Mark "Do not translate"', 'perflocale' )
					)
				);
			}

			var toolbar = el( BlockControls, { group: 'other' },
				el( ToolbarGroup, null,
					el( ToolbarDropdownMenu, {
						icon: 'translation',
						// Screen readers announce this label; the bare brand
						// name told a non-sighted user nothing about what the
						// button does.
						label: __( 'Translate with PerfLocale', 'perflocale' ),
						controls: [] // provided via children below
					},
					function ( controlProps ) {
						return el( Fragment, null,
							mkMenuItems( controlProps.onClose ),
							mkToolsGroup( controlProps.onClose )
						);
					} )
				)
			);

			return el( Fragment, null,
				toolbar,
				el( BlockEdit, props ),
				showWrap && el( WrapInConditionModal, {
					block: { name: props.name, attributes: props.attributes },
					clientId: props.clientId,
					onClose: function () { setShowWrap( false ); }
				} )
			);
		};
	}, 'withPerfLocaleToolbar' );

	addFilter( 'editor.BlockEdit', 'perflocale/block-toolbar', withPerfLocaleToolbar );

	// ---------------------------------------------------------------
	// Skip-translation marker: attribute + save-time data attr + editor
	// visual class. Only applied to blocks in SUPPORTED_BLOCKS so we
	// don't bloat the schema of every core block unnecessarily.
	// ---------------------------------------------------------------

	// 1. Register `perflocaleSkipTranslation` as a real block attribute
	// on every supported block. Gutenberg persists boolean attrs in
	// the block-comment delimiter, so saved posts look like
	// <!-- wp:paragraph {"perflocaleSkipTranslation":true} -->.
	addFilter(
		'blocks.registerBlockType',
		'perflocale/register-skip-attribute',
		function ( settings, name ) {
			// settings.attributes is passed explicitly because this filter
			// fires DURING block registration - at this moment
			// wp.blocks.getBlockType(name) still returns null, so the
			// schema-discovery branch of isTranslatableBlock would fail to
			// pick up custom blocks without the manual attribute pass-in.
			if ( ! isTranslatableBlock( name, settings && settings.attributes ) ) {
				return settings;
			}

			var extra = {};
			extra[ SKIP_ATTR ] = { type: 'boolean', default: false };

			settings.attributes = Object.assign( {}, settings.attributes || {}, extra );

			return settings;
		}
	);

	// 2. Emit `data-perflocale-skip="1"` into the saved HTML when the
	// attribute is true. This is the public contract server-side
	// code (and any third-party integration) can match against.
	addFilter(
		'blocks.getSaveContent.extraProps',
		'perflocale/emit-skip-data-attr',
		function ( extraProps, blockType, attributes ) {
			if ( ! isTranslatableBlock( blockType.name ) ) {
				return extraProps;
			}

			if ( attributes && attributes[ SKIP_ATTR ] === true ) {
				extraProps[ 'data-perflocale-skip' ] = '1';
			}

			return extraProps;
		}
	);

	// 3. Editor-canvas visual marker. Applied as a wrapper class on the
	// BlockListBlock so the amber outline + "Do not translate" label
	// pseudo-element show up, WITHOUT writing into the user's
	// className attribute. Users can still freely edit the
	// "Additional CSS classes" field without breaking the marker.
	var withSkipVisual = createHOC( function ( BlockListBlock ) {
		return function ( props ) {
			if ( ! isSkipMarked( props.attributes ) ) {
				return el( BlockListBlock, props );
			}

			var existing = props.className || '';
			var nextClass = existing
				? existing + ' ' + SKIP_VISUAL_CLASS
				: SKIP_VISUAL_CLASS;

			return el( BlockListBlock, Object.assign( {}, props, { className: nextClass } ) );
		};
	}, 'withPerfLocaleSkipVisual' );

	addFilter( 'editor.BlockListBlock', 'perflocale/skip-visual', withSkipVisual );

	/**
	 * Sibling-aware post-level fill: walk every leaf collected from the
	 * editor, look it up by position path in the source post, translate
	 * the resulting source text into this sibling's language, and write
	 * back to the same leaf. Used by the "Block translation" sidebar's
	 * "Fill all from source" action when the post being edited is a
	 * sibling of a source-language post.
	 *
	 * Loops the per-leaf endpoint sequentially. A batch /from-source
	 * endpoint would shave per-leaf round-trip overhead but introduces
	 * partial-failure handling complexity; sequential is simpler and the
	 * fully-cached path (TM hits everywhere) is fast enough in practice.
	 *
	 * @param {Array}         leaves - output of collectTranslatableBlocks
	 * @param {function|null} onDone - invoked on completion (success or fail)
	 */
	function fillAllFromSource( leaves, onDone ) {
		if ( ! TARGET_POST_ID || ! SOURCE_POST_ID || ! SOURCE_LANG ) {
			notice( __( 'No source post detected for this sibling.', 'perflocale' ), 'error' );
			onDone && onDone();
			return;
		}

		if ( ! Array.isArray( leaves ) || leaves.length === 0 ) {
			notice( __( 'No translatable text found in this post.', 'perflocale' ), 'warning' );
			onDone && onDone();
			return;
		}

		var targetLang = POST_SOURCE_LANG;

		notice(
			sprintf(
				/* translators: %d: number of blocks */
				__( 'Filling %d blocks from source…', 'perflocale' ),
				leaves.length
			),
			'info'
		);

		var success = 0;
		var failed = 0;
		var dispatch = wp.data.dispatch( 'core/block-editor' );
		var blockSelect = wp.data.select( 'core/block-editor' );

		function processNext( i ) {
			if ( i >= leaves.length ) {
				var summary;

				if ( failed === 0 ) {
					summary = sprintf(
						/* translators: %d: number of blocks */
						__( 'Filled %d blocks from source.', 'perflocale' ),
						success
					);
					notice( summary, 'success' );
				} else if ( success === 0 ) {
					summary = sprintf(
						/* translators: %d: number of blocks */
						__( 'Could not fill any of %d blocks from source.', 'perflocale' ),
						failed
					);
					notice( summary, 'error' );
				} else {
					summary = sprintf(
						/* translators: 1: filled count, 2: failed count */
						__( 'Filled %1$d blocks; %2$d could not be matched in source.', 'perflocale' ),
						success,
						failed
					);
					notice( summary, 'warning' );
				}

				onDone && onDone();
				return;
			}

			var leaf = leaves[ i ];
			var path = computeBlockPath( leaf.clientId );

			if ( ! path ) {
				failed++;
				processNext( i + 1 );
				return;
			}

			wp.apiFetch( {
				path: '/perflocale/v1/block-translate/from-source',
				method: 'POST',
				data: {
					target_post_id: TARGET_POST_ID,
					block_path:     path,
					target_lang:    targetLang,
					source_attr:    leaf.writeAttr || ''
				}
			} )
				.then( function ( response ) {
					var data = ( response && response.data && typeof response.data === 'object' ) ? response.data : response;

					if ( data && typeof data.translated === 'string' && data.translated !== '' ) {
						// Prefer the server's resolved attr over the client's hint.
						var writeAttr = ( typeof data.source_attr === 'string' && data.source_attr !== '' && data.source_attr !== 'innerHTML' )
							? data.source_attr
							: ( leaf.writeAttr || 'content' );

						// Verify the leaf still exists in the editor (user may
						// have removed blocks between collect and write).
						if ( blockSelect.getBlock( leaf.clientId ) ) {
							var update = {};
							update[ writeAttr ] = data.translated;
							dispatch.updateBlockAttributes( leaf.clientId, update );
							success++;
						} else {
							failed++;
						}
					} else {
						failed++;
					}
				} )
				.catch( function () {
					failed++;
				} )
				.finally( function () {
					processNext( i + 1 );
				} );
		}

		processNext( 0 );
	}

	// Public API for cross-file consumers (e.g. editor-sidebar.js needs the
	// walker + batch translator for the "Translate entire post" button).
	// Intentionally minimal surface: only the helpers a sidebar-level
	// "translate all" UI would need. Keeps internal helpers module-private.
	window.perflocaleBlockTranslate = {
		/**
		 * @param {string|undefined} clientId - undefined → all top-level blocks
		 * @return {Array} leaves (see collectTranslatableBlocks)
		 */
		collect: collectTranslatableBlocks,

		/**
		 * @param {Array} leaves - output of collect()
		 * @param {string} targetLang
		 * @param {function|null} onClose
		 */
		translate: translateBlocksWithMt,

		/**
		 * Sibling-aware post-level fill. No-op when not in sibling mode.
		 *
		 * @param {Array} leaves - output of collect()
		 * @param {function|null} onDone
		 */
		fillAllFromSource: fillAllFromSource
	};
} )();
