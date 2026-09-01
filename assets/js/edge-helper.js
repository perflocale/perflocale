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

// --- Language picker from Accept-Language + GeoIP --------------------------

function pickLanguage( request, config ) {
	const slugs = config.languages.map( ( l ) => l.slug );

	// 1. Honour an explicit ?lang=xx override (useful for testing).
	const url = new URL( request.url );
	const q = url.searchParams.get( 'lang' );

	if ( q && slugs.indexOf( q ) !== -1 ) {
		return q;
	}

	// 2. A perflocale_lang cookie means RETURNING visitor: WordPress set it
	// on their first routed request, and its own redirect features treat
	// its presence as "already chose — never steer again". The worker must
	// mirror that and stop steering entirely (return null = pass through,
	// the URL is the truth). Without this, a German-browser visitor who
	// clicks "English" in the switcher lands on an unprefixed default URL,
	// the worker re-derives "de" from Accept-Language, and rewrites them
	// straight back to German on every request, forever — the default
	// language becomes unreachable for them.
	const cookies = request.headers.get( 'cookie' ) || '';
	const sticky = cookies.match( /(?:^|;\s*)(?:perflocale_lang|perflocale_edge_lang)=([a-z0-9_-]+)/ );

	if ( sticky && slugs.indexOf( sticky[1] ) !== -1 ) {
		return null;
	}

	// 3. Cloudflare injects request.cf.country - use for geo steering.
	// (On Vercel: request.geo.country. On Netlify: context.geo.country.)
	const country = ( request.cf && request.cf.country ) || '';

	if ( country ) {
		const byCountry = {
			'BG': 'bg',
			'DE': 'de', 'AT': 'de', 'CH': 'de',
			'FR': 'fr',
			'ES': 'es', 'MX': 'es', 'AR': 'es'
			// ...extend as needed.
		};

		const slug = byCountry[ country.toUpperCase() ];

		if ( slug && slugs.indexOf( slug ) !== -1 ) {
			return slug;
		}
	}

	// 4. Fall through to Accept-Language.
	const accept = request.headers.get( 'accept-language' ) || '';

	if ( accept ) {
		const preferred = accept
			.split( ',' )
			.map( ( part ) => part.trim().split( ';' )[0].split( '-' )[0].toLowerCase() );

		for ( const candidate of preferred ) {
			if ( slugs.indexOf( candidate ) !== -1 ) {
				return candidate;
			}
		}
	}

	// 5. Default.
	return config.default_slug;
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

// Has the URL already been routed to a language, per the site's url_mode?
function isAlreadyRouted( url, config ) {
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
		( l ) => l.prefix && url.pathname.startsWith( '/' + l.prefix + '/' )
	);
}

async function handle( request ) {
	const config = await loadConfig();
	const slug = pickLanguage( request, config );

	// If the URL is already language-routed for this site's url_mode, do nothing.
	const url = new URL( request.url );

	if ( isAlreadyRouted( url, config ) ) {
		return fetch( request );
	}

	// If this is an excluded path (REST, admin, etc.) pass through untouched.
	for ( const excluded of config.excluded_paths ) {
		if ( url.pathname.indexOf( excluded ) === 0 ) {
			return fetch( request );
		}
	}

	// === Strategy A: URL rewrite (recommended) ============================
	// Rewrites into the form the site's url_mode actually routes. The
	// default language always stays on the un-rewritten URL.
	if ( slug && slug !== config.default_slug ) {
		const target = config.languages.find( ( l ) => l.slug === slug );

		if ( target ) {
			const mode = config.url_mode || 'subdirectory';

			if ( mode === 'query' ) {
				url.searchParams.set( 'lang', target.slug );
				return fetch( new Request( url.toString(), request ) );
			}

			if ( mode === 'subdomain' || mode === 'domain' ) {
				const host = langHostname( target.domain );

				if ( host ) {
					url.hostname = host;
					return fetch( new Request( url.toString(), request ) );
				}

				return fetch( request ); // No domain configured - pass through.
			}

			if ( target.prefix ) {
				url.pathname = '/' + target.prefix + url.pathname;
				return fetch( new Request( url.toString(), request ) );
			}
		}
	}

	// === Strategy B: Header hint ==========================================
	// Mode-agnostic alternative: forward unchanged, let WordPress route.
	// const headers = new Headers( request.headers );
	// headers.set( 'X-PerfLocale-Lang', slug );
	// return fetch( new Request( request, { headers } ) );

	return fetch( request );
}
