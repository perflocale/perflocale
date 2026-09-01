/**
 * Sprintf-placeholder validation for the Strings admin page.
 *
 * Two consumers share the extractor:
 *
 *   1. The Edit Translation MODAL — live-highlights placeholders as the
 *      user types. Each chip turns green when present, red when missing,
 *      amber when over-counted. Clicking a missing chip inserts the token
 *      at the textarea's caret. Clicking Apply on a non-clean state shows
 *      a confirm dialog (still allows save — translators sometimes
 *      legitimately drop a placeholder when collapsing args). This is the
 *      authoritative validation gate for translators editing one string
 *      at a time.
 *
 *   2. The PER-ROW INLINE indicator — paints a red border + ⚠ icon on any
 *      row whose translation is missing a source placeholder, updated on
 *      every keystroke. Surfaces issues in the table view without forcing
 *      the user into the modal. Save-time gating intentionally relies on
 *      the inline visibility rather than a blocking page-submit confirm:
 *      the previous form-level confirm() dialog proved more disruptive
 *      than helpful (most users dismissed without reading), so it was
 *      removed in favour of the always-visible inline marker.
 *
 * Regex must match `\PerfLocale\Util\SprintfTokens::PATTERN` in PHP exactly so the
 * server and client never disagree.
 */
( function ( global ) {
	'use strict';

	// Mirrors \PerfLocale\Util\SprintfTokens::PATTERN. Two alternations:
	// - The first explicitly handles the common "leading zero + digit width"
	//   form (`%05d`, `%07s`) so leading zeros aren't swallowed by the
	//   flags-only group on the right.
	// - The second covers the full spec: optional positional + flags +
	//   width-or-`*` + .precision + specifier.
	var TOKEN_RE = /%(%|(?:\d+\$)?[-+ #0']*\d*[bcdeEfFgGoOsuxX]|(?:\d+\$)?[-+ #0']*(?:\d+|\*)?(?:\.(?:\d+|\*))?[bcdeEfFgGoOsuxX])/g;

	function extractPlaceholders( text ) {
		if ( ! text ) {
			return [];
		}
		var out = [];
		var seen = {};
		var m;
		// Reset lastIndex on a stateful /g regex before reuse.
		TOKEN_RE.lastIndex = 0;
		while ( ( m = TOKEN_RE.exec( text ) ) !== null ) {
			if ( m[ 1 ] === '%' ) {
				continue; // %% literal escape — skip
			}
			if ( ! seen[ m[ 0 ] ] ) {
				seen[ m[ 0 ] ] = true;
				out.push( m[ 0 ] );
			}
		}
		return out;
	}

	// Count occurrences of a literal substring without regex escaping
	// hassles (placeholders contain regex metacharacters like $).
	function countOccurrences( haystack, needle ) {
		if ( ! haystack || ! needle ) return 0;
		var count = 0;
		var idx = haystack.indexOf( needle );
		while ( idx !== -1 ) {
			count++;
			idx = haystack.indexOf( needle, idx + needle.length );
		}
		return count;
	}

	function comparePlaceholders( source, translation ) {
		var tokens = extractPlaceholders( source );
		var states = {};
		tokens.forEach( function ( token ) {
			var srcCount = countOccurrences( source, token );
			var trgCount = countOccurrences( translation, token );
			if ( trgCount === 0 || trgCount < srcCount ) {
				states[ token ] = 'missing';
			} else if ( trgCount > srcCount ) {
				states[ token ] = 'extra';
			} else {
				states[ token ] = 'present';
			}
		} );
		return { tokens: tokens, states: states };
	}

	// Public API.
	global.perflocaleStrValidate = {
		extract: extractPlaceholders,
		compare: comparePlaceholders,
		count: countOccurrences,
	};
} )( window );
