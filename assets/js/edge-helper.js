/**
 * PerfLocale - Edge integration helper (reference implementation).
 *
 * This file is NOT loaded by WordPress. It's a copy-paste-friendly
 * snippet showing how a Cloudflare Worker, Vercel Edge function,
 * or Netlify Edge function can pre-route a visitor to the right
 * language BEFORE the request hits PHP.
 *
 * Usage:
 * 1. Copy this into your edge runtime (Worker, Edge function, etc.)
 * 2. Set CONFIG_URL to your site's /wp-json/perflocale/v1/config
 * 3. Enable "edge_integration_enabled" in PerfLocale settings
 * 4. Add 'edge_hint' to your detection order
 *
 * The worker fetches the config once, caches it at the edge, and
 * either (a) rewrites the URL into the language's routed form before
 * forwarding to origin, or (b) adds an X-PerfLocale-Lang header so
 * WordPress's detect_from_edge_hint() picks up the decision.
 *
 * Strategy A branches on config.url_mode: subdirectory prepends the
 * path prefix, query appends ?lang=<slug>, subdomain/domain swap the
 * hostname. Strategy B is mode-agnostic.
 *
 * @package PerfLocale
 * @license MIT - take this code, adapt freely.
 */

// --- Configuration ----------------------------------------------------------

const CONFIG_URL = 'https://example.com/wp-json/perflocale/v1/config';
const CACHE_TTL_MS = 10 * 60 * 1000; // Refresh config at most every 10 min.

// --- Config fetcher (single-flight, edge-cached) ---------------------------

let cachedConfig = null;
let cachedConfigExp = 0;
let inflightRequest = null;

async function loadConfig() {
	const now = Date.now();

	if ( cachedConfig && now < cachedConfigExp ) {
		return cachedConfig;
	}

	if ( inflightRequest ) {
		return inflightRequest;
	}

	inflightRequest = fetch( CONFIG_URL, {
		headers: { 'Accept': 'application/json' },
		cf: { cacheEverything: true, cacheTtl: 600 } // Cloudflare hint.
	} )
		.then( ( r ) => {
			if ( ! r.ok ) {
				throw new Error( 'PerfLocale config fetch failed: ' + r.status );
			}
			return r.json();
		} )
		.then( ( cfg ) => {
			cachedConfig = cfg;
			cachedConfigExp = Date.now() + CACHE_TTL_MS;
			return cfg;
		} )
		.finally( () => { inflightRequest = null; } );

	return inflightRequest;
}

// --- Accept-Language matching (same rules as the plugin's AcceptLanguageMatcher) ---
//
// Per tag: locale tag (exact, truncated, script dropped, zh script twin), a slug of the same base, then the base: a bare
// tag the default language shares goes there, else the base's default region or bare locale, the default language, the
// first in order. Refused (q=0) languages are skipped. The entry limit is fixed at 20 (the plugin's filter default).

const ACCEPT_LANGUAGE_LIMIT = 20;
const DEFAULT_REGION = new Map( Object.entries( { en: 'us', zh: 'cn', ja: 'jp', ko: 'kr', sv: 'se', da: 'dk', el: 'gr', cs: 'cz', uk: 'ua', he: 'il', nb: 'no', nn: 'no', sr: 'rs', sl: 'si', et: 'ee', ms: 'my', sq: 'al', bs: 'ba' } ) );
const BASE_ALIAS = new Map( Object.entries( { no: 'nb', iw: 'he', in: 'id' } ) );
const ZH_SCRIPT = new Map( Object.entries( { cn: 'hans', sg: 'hans', my: 'hans', tw: 'hant', hk: 'hant', mo: 'hant' } ) );
const LOCALE_RE = /^([a-z]{2,3})(?:-([a-z\d]{1,8}))?(-[a-z\d]{1,8})?(?:-[a-z\d]{1,8})*$/;

const localeTag = ( l ) => String( l.locale || '' ).replace( /[_@]/g, '-' ).toLowerCase();

let langIndex = null;

// Built once per config object (the config is cached for CACHE_TTL_MS).
function langIndexFor( config ) {
	if ( langIndex && langIndex.config === config ) {
		return langIndex;
	}

	const tags = new Map(), short = new Map(), twins = new Map(), bases = new Map(), held = new Set(), regions = new Map();
	const slugs = new Map();
	const setOnce = ( m, k, v ) => { if ( ! m.has( k ) ) { m.set( k, v ); } };
	let defaultBase = '';

	for ( const l of config.languages || [] ) {
		const tag = localeTag( l );
		const m = LOCALE_RE.exec( tag );
		slugs.set( l.slug, m ? m[ 1 ] : '' );

		if ( ! m ) {
			continue;
		}

		const base = m[ 1 ], second = m[ 2 ] || '', script = second.length === 4;
		const region = script ? ( m[ 3 ] || '' ).slice( 1 ) : second;
		const want = DEFAULT_REGION.get( base ) || base;

		setOnce( tags, tag, l.slug );

		if ( l.slug === config.default_slug ) {
			defaultBase = base;
		}

		// Base pick: the default region (a bare locale counts) holds; else the default language; else the first.
		if ( ! held.has( base ) ) {
			if ( region === want || second === '' ) {
				bases.set( base, l.slug );
				held.add( base );
			} else if ( l.slug === config.default_slug || ! bases.has( base ) ) {
				bases.set( base, l.slug );
			}
		}

		if ( m[ 3 ] ) {
			const parts = tag.split( '-' );

			for ( let i = parts.length - 1; i >= 2; i-- ) {
				setOnce( short, parts.slice( 0, i ).join( '-' ), l.slug );
			}
		}

		if ( base === 'zh' && ! script && ZH_SCRIPT.has( region ) ) {
			setOnce( twins, 'zh-' + ZH_SCRIPT.get( region ), l.slug );
		}

		// Country step: the locale's 2-letter region, as the plugin's GeoRedirect derives it (never the default).
		if ( ! l.is_default && /^[a-z]{2}$/.test( second ) ) {
			setOnce( regions, second.toUpperCase(), [] );
			regions.get( second.toUpperCase() ).push( l.slug );
		}
	}

	for ( const [ k, v ] of short ) {
		setOnce( tags, k, v );
	}

	langIndex = { config, tags, twins, bases, regions, slugs, defaultBase };
	return langIndex;
}

// Accepted tags by quality (equal q in header order) and refused (q=0) tags; a repeated tag keeps its first weight.
function parseAcceptLanguage( header ) {
	const seen = new Map();

	String( header ).split( ',', ACCEPT_LANGUAGE_LIMIT ).forEach( ( part ) => {
		const m = /^\s*([a-z]{1,8})((?:[-_][a-z\d]{1,8}){0,7})\s*(?:;\s*q\s*=\s*([\d.]{1,6})\s*)?$/i.exec( part );

		if ( ! m ) {
			return;
		}

		const base = m[ 1 ].toLowerCase();
		const tag = ( BASE_ALIAS.get( base ) || base ) + m[ 2 ].replace( /_/g, '-' ).toLowerCase();
		const q = m[ 3 ] === undefined ? 1 : Math.min( 1, parseFloat( m[ 3 ] ) );

		if ( ! seen.has( tag ) ) {
			seen.set( tag, [ q > 0 ? q : 0, seen.size ] );
		}
	} );

	const all = [ ...seen ];
	return {
		tags: all.filter( ( e ) => e[ 1 ][ 0 ] > 0 ).sort( ( a, b ) => b[ 1 ][ 0 ] - a[ 1 ][ 0 ] || a[ 1 ][ 1 ] - b[ 1 ][ 1 ] ).map( ( e ) => e[ 0 ] ),
		refused: all.filter( ( e ) => ! ( e[ 1 ][ 0 ] > 0 ) ).map( ( e ) => e[ 0 ] ),
	};
}

// Slugs whose most specific matching range (RFC 4647 basic filtering) in the header is refused.
function blockedSlugs( parsed, config ) {
	const blocked = new Set();

	if ( ! parsed.refused.length ) {
		return blocked;
	}

	for ( const l of config.languages || [] ) {
		const tag = localeTag( l );
		let best = -1, no = false;

		[ parsed.refused, parsed.tags ].forEach( ( ranges, i ) => {
			for ( const range of ranges ) {
				const len = range.split( '-' ).length - 1;

				if ( len > best && ( tag === range || tag.startsWith( range + '-' ) ) ) {
					best = len;
					no = i === 0;
				}
			}
		} );

		if ( no ) {
			blocked.add( l.slug );
		}
	}

	return blocked;
}

// The base pick without refused languages (the index holds the unrefused pick).
function pickBase( base, config, blocked ) {
	const want = DEFAULT_REGION.get( base ) || base;
	let pick = null;

	for ( const l of config.languages || [] ) {
		const m = LOCALE_RE.exec( localeTag( l ) );

		if ( blocked.has( l.slug ) || ! m || m[ 1 ] !== base ) {
			continue;
		}

		const second = m[ 2 ] || '';
		const region = second.length === 4 ? ( m[ 3 ] || '' ).slice( 1 ) : second;

		if ( region === want || second === '' ) {
			return l.slug;
		}

		if ( pick === null || l.slug === config.default_slug ) {
			pick = l.slug;
		}
	}

	return pick;
}

// One tag's slug, or null.
function matchTag( tag, idx, config, blocked ) {
	const parts = tag.split( '-' );
	const n = parts.length, base = parts[ 0 ];
	const script = n > 1 && parts[ 1 ].length === 4;
	const cands = [ tag ];

	for ( let i = n - 1; i >= 2; i-- ) {
		cands.push( parts.slice( 0, i ).join( '-' ) );
	}

	if ( script && n > 2 ) {
		cands.push( base + '-' + parts[ 2 ] );
	}

	if ( base === 'zh' && n > 1 && ZH_SCRIPT.has( parts[ script ? 2 : 1 ] ) ) {
		cands.push( 'zh-' + ZH_SCRIPT.get( parts[ script ? 2 : 1 ] ) );
	}

	for ( const level of [ idx.tags, idx.twins ] ) {
		for ( const c of cands ) {
			const hit = level.get( c );

			if ( hit !== undefined && ! blocked.has( hit ) ) {
				return hit;
			}
		}
	}

	// A slug is no language tag: "br" (Breton) must not reach pt_BR's slug br.
	if ( n > 1 && idx.slugs.get( tag ) === base && ! blocked.has( tag ) ) {
		return tag;
	}

	if ( n === 1 && base === idx.defaultBase && ! blocked.has( config.default_slug ) ) {
		return config.default_slug;
	}

	const pick = idx.bases.has( base ) ? idx.bases.get( base ) : null;
	return pick === null || ! blocked.has( pick ) ? pick : pickBase( base, config, blocked );
}

// The active slug the header prefers and the refused slugs.
function acceptLanguage( header, config ) {
	const idx = langIndexFor( config );
	const parsed = parseAcceptLanguage( header );
	const blocked = blockedSlugs( parsed, config );

	for ( const tag of parsed.tags ) {
		const slug = matchTag( tag, idx, config, blocked );

		if ( slug !== null ) {
			return { slug, blocked };
		}
	}

	return { slug: null, blocked };
}

function matchAcceptLanguage( header, config ) {
	return acceptLanguage( header, config ).slug;
}

// --- Language picker from Accept-Language + GeoIP --------------------------

// facts.country: the visitor's country took part in the decision (no request header carries it, so no Vary can).
function pickLanguage( request, config, facts = {} ) {
	const slugs = config.languages.map( ( l ) => l.slug );

	// 1. Honour an explicit ?lang=xx override (useful for testing).
	const url = new URL( request.url );
	const q = url.searchParams.get( 'lang' );

	if ( q && slugs.indexOf( q ) !== -1 ) {
		return q;
	}

	// 2. A perflocale_lang cookie means RETURNING visitor: WordPress set it
	// on their first routed request, and its own redirect features treat
	// its presence as "already chose - never steer again". The worker must
	// mirror that and stop steering entirely (return null = pass through,
	// the URL is the truth). Without this, a German-browser visitor who
	// clicks "English" in the switcher lands on an unprefixed default URL,
	// the worker re-derives "de" from Accept-Language, and rewrites them
	// straight back to German on every request, forever - the default
	// language becomes unreachable for them.
	const cookies = request.headers.get( 'cookie' ) || '';
	const sticky = cookies.match( /(?:^|;\s*)(?:perflocale_lang|perflocale_edge_lang)=([a-z0-9_-]+)/ );

	if ( sticky && slugs.indexOf( sticky[1] ) !== -1 ) {
		return null;
	}

	// 3. One decision, in the site's redirect order (config.redirect_order, the plugin's redirect_priority_order):
	// the browser's languages first unless geo is ordered before them, the country only when the first one has none.
	const browser = acceptLanguage( request.headers.get( 'accept-language' ) || '', config );
	const country = countryLanguage( request, config, browser.blocked, slugs );
	const order = config.redirect_order || [];
	const geoFirst = order.indexOf( 'geo' ) !== -1 && order.indexOf( 'geo' ) < order.indexOf( 'browser' );

	facts.country = !! ( request.cf && request.cf.country ) && ( geoFirst || ! browser.slug );

	return ( geoFirst ? country || browser.slug : browser.slug || country ) || config.default_slug;
}

// The country's language (Cloudflare request.cf.country; Vercel request.geo.country; Netlify context.geo.country):
// first a non-default language whose locale has this region (de_AT -> AT), then the example table; never a refused one.
function countryLanguage( request, config, blocked, slugs ) {
	const country = ( ( request.cf && request.cf.country ) || '' ).toUpperCase();

	if ( ! country ) {
		return null;
	}

	const own = ( langIndexFor( config ).regions.get( country ) || [] ).find( ( s ) => ! blocked.has( s ) );

	if ( own ) {
		return own;
	}

	const byCountry = {
		'BG': 'bg',
		'DE': 'de', 'AT': 'de', 'CH': 'de',
		'FR': 'fr',
		'ES': 'es', 'MX': 'es', 'AR': 'es'
		// ...extend as needed.
	};

	const slug = byCountry[ country ];

	return slug && slugs.indexOf( slug ) !== -1 && ! blocked.has( slug ) ? slug : null;
}

// --- Cloudflare Worker entrypoint ------------------------------------------
//
// Two strategies. Uncomment the one that fits your site:
//
// (A) URL rewrite - adds the language prefix and forwards (transparent).
// (B) Header hint - forwards unchanged with X-PerfLocale-Lang set.
//
// Strategy A is most cache-friendly (the rewritten URL is the cache key).
// Strategy B is simpler but emits Vary-dependent content per header value.

addEventListener( 'fetch', ( event ) => {
	event.respondWith( handle( event.request ) );
} );

// Normalize a config domain entry ("de.example.com" or a full URL) to a hostname.
function langHostname( domain ) {
	return ( domain || '' ).replace( /^https?:\/\//, '' ).replace( /\/.*$/, '' );
}

// Percent-decode the way PHP's rawurldecode() does: over the UTF-8 bytes,
// leaving a malformed escape ("%ZZ", a trailing "%E6%97") as it is, never
// throwing. Returns a byte string (one char per byte), so comparisons are
// byte for byte, as in PHP.
function rawUrlDecodeBytes( value ) {
	const bytes = new TextEncoder().encode( String( value ) );
	const isHex = ( b ) => ( b >= 0x30 && b <= 0x39 ) || ( b >= 0x41 && b <= 0x46 ) || ( b >= 0x61 && b <= 0x66 );
	let out = '';

	for ( let i = 0; i < bytes.length; i++ ) {
		if ( bytes[ i ] === 0x25 && i + 2 < bytes.length && isHex( bytes[ i + 1 ] ) && isHex( bytes[ i + 2 ] ) ) {
			out += String.fromCharCode( parseInt( String.fromCharCode( bytes[ i + 1 ], bytes[ i + 2 ] ), 16 ) );
			i += 2;
		} else {
			out += String.fromCharCode( bytes[ i ] );
		}
	}

	return out;
}

// Is the path inside one of the excluded paths? The same rule as the plugin's
// Helper::path_matches_excluded(): both sides percent-decoded, and a match only
// on the whole path or at a boundary after the excluded path ("/", "." or "?"),
// so an excluded "/api" does not cover "/apifoo", and "/日本語/" matches the
// encoded path a browser sends.
function pathMatchesExcluded( path, needles ) {
	const normalised = '/' + rawUrlDecodeBytes( path ).replace( /^\/+/, '' );

	for ( const needle of needles || [] ) {
		const candidate = rawUrlDecodeBytes( needle ).replace( /\/+$/, '' );

		if ( candidate === '' ) {
			continue;
		}

		if ( normalised === candidate ||
			normalised.startsWith( candidate + '/' ) ||
			normalised.startsWith( candidate + '.' ) ||
			normalised.startsWith( candidate + '?' ) ) {
			return true;
		}
	}

	return false;
}

// Has the URL already been routed to a language, per the site's url_mode? `rel` is the path below the home path.
function isAlreadyRouted( url, config, rel ) {
	const mode = config.url_mode || 'subdirectory';

	if ( mode === 'query' ) {
		const q = url.searchParams.get( 'lang' );
		return !! ( q && config.languages.some( ( l ) => l.slug === q ) );
	}

	if ( mode === 'subdomain' || mode === 'domain' ) {
		return config.languages.some( ( l ) => {
			const host = langHostname( l.domain );
			return host && url.hostname === host;
		} );
	}

	// subdirectory (default; also covers configs cached before url_mode shipped)
	return config.languages.some(
		( l ) => l.prefix && ( rel === '/' + l.prefix || rel.startsWith( '/' + l.prefix + '/' ) )
	);
}

// The plugin's LanguageRouter::BOT_UA_PATTERN and STATIC_EXTENSIONS (tests/js/edge-handle.test.cjs keeps them equal).
const BOT_UA = /\b(bot|crawl|spider|slurp|mediapartners|googlebot|bingbot|yandex|baidu|twitterbot|applebot|facebookexternalhit|linkedinbot|duckduckbot|gptbot|slackbot|discordbot|telegrambot|petalbot|ahrefsbot|semrushbot|pinterestbot|oai-searchbot|chatgpt-user|claudebot|claude-web|claude-user|claude-searchbot|anthropic-ai|perplexitybot|perplexity-user|ccbot|bytespider|amazonbot|meta-externalagent|meta-externalfetcher|duckassistbot|mistralai-user|cohere-ai|diffbot)\b/i;
const STATIC_EXTENSIONS = new Set( [ 'ico', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'css', 'js', 'mjs', 'woff', 'woff2', 'ttf', 'eot', 'map', 'webmanifest' ] );

// Steer only a person's GET/HEAD page request: never a bot (cloaking), a form post, an asset or a WordPress file.
function steerable( request, rel ) {
	if ( request.method !== 'GET' && request.method !== 'HEAD' ) {
		return false;
	}

	if ( BOT_UA.test( request.headers.get( 'user-agent' ) || '' ) ) {
		return false;
	}

	const last = rel.slice( rel.lastIndexOf( '/' ) + 1 );
	const dot = last.lastIndexOf( '.' );

	if ( dot > 0 && STATIC_EXTENSIONS.has( last.slice( dot + 1 ).toLowerCase() ) ) {
		return false;
	}

	return ! /^\/(?:wp-[^/]*|xmlrpc\.php)(?:\/|$)/.test( rel );
}

// The answer at a steerable URL depends on the visitor, so no downstream cache may share it across visitors;
// one the country decided is stored by none (a cache in front of the worker cannot vary on it).
async function varied( pending, byCountry = false ) {
	const response = await pending;
	const out = new Response( response.body, response );

	out.headers.append( 'Vary', 'Accept-Language, Cookie' );

	if ( byCountry ) {
		out.headers.set( 'Cache-Control', 'private, no-store' );

		for ( const name of [ ...out.headers.keys() ] ) {
			if ( /^(?:surrogate-control|(?:.+-)?cdn-cache-control)$/.test( name ) ) {
				out.headers.set( name, 'no-store' );
			}
		}
	}

	return out;
}

async function handle( request ) {
	const config = await loadConfig();
	const url = new URL( request.url );

	// The path below the site's home path (a subfolder install keeps its folder in front of the prefix).
	const home = ( config.home_path || '/' ).replace( /\/+$/, '' );
	const inHome = home === '' || url.pathname === home || url.pathname.startsWith( home + '/' );
	const rel = inHome ? url.pathname.slice( home.length ) || '/' : '';

	// Outside the site, not a person's page request, already routed, or excluded (REST, admin, ...): untouched.
	if ( ! inHome || ! steerable( request, rel ) || isAlreadyRouted( url, config, rel ) || pathMatchesExcluded( rel, config.excluded_paths ) ) {
		return fetch( request );
	}

	const facts = {};
	const slug = pickLanguage( request, config, facts );
	const vary = ( pending ) => varied( pending, facts.country );

	// === Strategy A: URL rewrite (recommended) ============================
	// Rewrites into the form the site's url_mode actually routes. The
	// default language always stays on the un-rewritten URL.
	if ( slug && slug !== config.default_slug ) {
		const target = config.languages.find( ( l ) => l.slug === slug );

		if ( target ) {
			const mode = config.url_mode || 'subdirectory';

			if ( mode === 'query' ) {
				url.searchParams.set( 'lang', target.slug );
				return vary( fetch( new Request( url.toString(), request ) ) );
			}

			if ( mode === 'subdomain' || mode === 'domain' ) {
				const host = langHostname( target.domain );

				if ( host ) {
					url.hostname = host;
					return vary( fetch( new Request( url.toString(), request ) ) );
				}

				return vary( fetch( request ) ); // No domain configured - pass through.
			}

			if ( target.prefix ) {
				url.pathname = home + '/' + target.prefix + rel;
				return vary( fetch( new Request( url.toString(), request ) ) );
			}
		}
	}

	// === Strategy B: Header hint ==========================================
	// Mode-agnostic alternative: forward unchanged, let WordPress route.
	// const headers = new Headers( request.headers );
	// headers.set( 'X-PerfLocale-Lang', slug );
	// return vary( fetch( new Request( request, { headers } ) ) );

	return vary( fetch( request ) );
}
