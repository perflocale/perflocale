<?php
/**
 * URL bases runtime: link localization, reverse lookup and the parse-time swap forwarders.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases;

use PerfLocale\Plugin;
use PerfLocale\Router\LanguageRouter;
use PerfLocale\Router\UrlConverter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the compiled map (option perflocale_url_bases_map, v1); loaded only when a blog has one. The swap body is
 * the cold Swap class, loaded only by a request whose path holds a base segment it may change.
 *
 * @phpstan-type Entry array{key: string, segs: array<int, string>|string, lang: string, kind: string}
 * @phpstan-type Map array{v: int, gen: int, state: string, redirect?: bool, keys: array<string, array{grp?: string, role?: string, eff?: string, bare?: bool, shr?: array<string, array<int, string>>, pgs?: array<int, string>, exc?: array<string, array<string, array<int, string>>>}>, tr?: array<string, array<string, string>>, alias: array<string, array<int, Entry>>, exc?: array<mixed>, off?: array<string, mixed>, own?: array<string, array<string, string>>, unv?: array<int, string>, rg?: string}
 * @phpstan-type Span array{start: int, len: int, canonical: string, entry: Entry, reg: string, bytes: string}
 * @phpstan-type Hit array{start: int, len: int, canonical: string, kind: string, key: string, lang: string, grp: string, bytes: string}
 * @phpstan-type Ctx array{mode: string, hide: bool, default: string, hosts: array<string, string>, path: string, r: callable(string): ?string, m: array<string, ?string>}
 * @since 1.0.8
 */
final class Runtime {

	/**
	 * Compiled map option.
	 */
	public const OPTION = 'perflocale_url_bases_map';

	/**
	 * Owned fuse arg: a request carrying it gets no base redirect.
	 */
	public const FUSE = 'perflocale_alias';

	/**
	 * Path segments find() tries as a base start; the compiler refuses a key with a longer front (index.php included).
	 */
	public const DEPTH = 8;

	/**
	 * Hooks registered.
	 *
	 * @var bool
	 */
	private static bool $on = false;

	/**
	 * Usable map per blog id; [] when absent or not usable.
	 *
	 * @var array<int, Map|array{}>
	 */
	private static array $maps = [];

	/**
	 * URL facts per blog id.
	 *
	 * @var array<int, Ctx>
	 */
	private static array $ctx = [];

	/**
	 * Canonical bases per "blog|key|lang" and link results per "blog|hook|lang|url".
	 *
	 * @var array<string, string>
	 */
	private static array $memo = [];

	/**
	 * Prepared alias entries per "blog|first segment".
	 *
	 * @var array<string, array<int, array{0: array<int, string>, 1: string, 2: Entry, 3: string}>>
	 */
	private static array $cands = [];

	/**
	 * Register the hooks once; Bootstrap calls it only when a map exists (also from pre_cache_alloptions: no option
	 * read here).
	 *
	 * @return void
	 */
	public static function maybe_register(): void {
		if ( self::$on ) {
			return;
		}

		self::$on = true;
		$hooks    = [
			[ 'link', 20, 1, [ 'post_type_link', 'term_link', 'post_type_archive_link', 'post_type_archive_feed_link', 'attachment_link', 'render_block_core/navigation-link', 'render_block_core/navigation-submenu', 'url_to_postid' ] ],
			[ 'link', 10, 2, [ 'perflocale/url/convert' ] ],
			[ 'arm', PHP_INT_MAX, 2, [ 'do_parse_request' ] ],
			[ 'changed', 10, 1, [ 'add_option_perflocale_url_bases_map', 'update_option_perflocale_url_bases_map', 'delete_option_perflocale_url_bases_map', 'add_option_rewrite_rules', 'update_option_rewrite_rules', 'delete_option_rewrite_rules' ] ],
		];

		foreach ( $hooks as [ $method, $priority, $args, $names ] ) {
			foreach ( $names as $name ) {
				add_filter( $name, [ self::class, $method ], $priority, $args );
			}
		}

		if ( ! is_admin() ) {
			add_filter( 'wp_redirect', [ ForeignLocation::class, 'filter' ], 1 );
		}
	}

	/**
	 * Link filters (:20, after the language marker): the base follows the URL's own marker; url_to_postid: the
	 * registered base, which the stored rules route; perflocale/url/convert: the target language's base, and the owned
	 * fuse arg never survives; navigation blocks: the first href.
	 *
	 * @param mixed $value Link, URL or rendered navigation block.
	 * @param mixed $slug  Target language slug (perflocale/url/convert).
	 * @return mixed
	 */
	public static function link( $value, $slug = null ) {
		$hook = (string) current_filter();
		$conv = $hook === 'perflocale/url/convert';
		$lang = $conv && is_string( $slug ) && $slug !== '' ? $slug : null;

		// With attachment pages off, core sends the page to the file: its base is left alone (Astra H4).
		if ( ! is_string( $value ) || ( $hook === 'attachment_link' && ! get_option( 'wp_attachment_pages_enabled' ) ) ) {
			return $value;
		}

		if ( str_starts_with( $hook, 'render_block' ) ) {
			// The marker is read from the decoded URL (esc_url() writes &#038;); an unchanged href keeps its bytes.
			return preg_replace_callback(
				'/(?<=\s)href="([^"]+)"/',
				static function ( array $m ): string {
					$url = html_entity_decode( $m[1] );
					$new = self::rewrite( $url, null, false );

					return $new === $url ? $m[0] : 'href="' . esc_attr( $new ) . '"';
				},
				$value,
				1
			) ?? $value;
		}

		// A page builds the same links many times; the memo keeps repeats at one lookup.
		$memo = get_current_blog_id() . "|$hook|$lang|$value";

		if ( ! isset( self::$memo[ $memo ] ) ) {
			self::$memo         = count( self::$memo ) > 2000 ? [] : self::$memo;
			self::$memo[ $memo ] = self::rewrite( $conv && str_contains( $value, self::FUSE ) ? remove_query_arg( self::FUSE, $value ) : $value, $lang, $hook === 'url_to_postid' );
		}

		return self::$memo[ $memo ];
	}

	/**
	 * Replace a base alias span with the canonical base of the URL's own language; any other URL is unchanged.
	 *
	 * @api Redirect\Sender (5.3 step 4), ForeignLocation and the term rule call it.
	 * @param string $url Absolute or root-relative URL.
	 * @return string
	 */
	public static function canonicalize_url( string $url ): string {
		return self::rewrite( $url, null, false );
	}

	/**
	 * The base span at the start of a base-relative path, for the request's language unless one is given.
	 *
	 * @param string $rel Path after home and language marker.
	 * @return array<string, int|string>|null start, len, canonical, kind, key, lang.
	 */
	public static function locate_base_span( string $rel ): ?array {
		return Swap::locate( $rel );
	}

	/**
	 * The main request's base span (start, len in raw REQUEST_URI bytes; grp = the key's group), or null.
	 *
	 * @return Hit|null
	 */
	public static function span(): ?array {
		return class_exists( Swap::class, false ) ? Swap::span() : null;
	}

	/**
	 * REQUEST_URI as received, also inside the rule-matching window.
	 *
	 * @return string
	 */
	public static function raw_request(): string {
		return class_exists( Swap::class, false ) ? Swap::raw() : self::server( 'REQUEST_URI' );
	}

	/**
	 * This blog's usable map, [] when absent or not usable.
	 *
	 * @return Map|array{}
	 */
	public static function map(): array {
		$blog = get_current_blog_id();

		if ( ! isset( self::$maps[ $blog ] ) ) {
			// Not autoloaded (a switched-to blog without a map): get_option() would query.
			$map = isset( wp_load_alloptions()[ self::OPTION ] ) ? get_option( self::OPTION ) : false;

			self::$maps[ $blog ] = self::usable( $map ) ? $map : [];
		}

		return self::$maps[ $blog ];
	}

	/**
	 * Map v1 with its required parts and at most 16 unverified routes (Q17: more suspends every base).
	 *
	 * @param mixed $map Stored option value.
	 * @return bool
	 * @phpstan-assert-if-true Map $map
	 */
	public static function usable( $map ): bool {
		return is_array( $map ) && ( $map['v'] ?? 0 ) === 1 && isset( $map['state'], $map['keys'], $map['alias'] ) && count( (array) ( $map['unv'] ?? [] ) ) <= 16;
	}

	/**
	 * The map changed (drop this request's copies); rewrite rules written, also by a front-end self-heal: the cold
	 * compiler lifecycle marks the map dirty.
	 *
	 * @return void
	 */
	public static function changed(): void {
		self::$maps = self::$memo = self::$cands = [];

		if ( ! str_ends_with( (string) current_filter(), self::OPTION ) ) {
			Lifecycle::schedule( 'rewrite_rules' );
		}
	}

	/**
	 * Do_parse_request (PHP_INT_MAX): arm the cold swap when this parse runs and the raw path holds a base segment
	 * the swap may change; once loaded, every later parse re-arms or drops it.
	 *
	 * @param mixed $parse Whether core parses.
	 * @param mixed $wp    Environment.
	 * @return mixed
	 */
	public static function arm( $parse, $wp = null ) {
		$uri = self::server( 'REQUEST_URI' );
		$pi  = self::server( 'PATH_INFO' );
		// The query string may hold a false hit too: it only loads Swap, which then finds no span.
		$hit = true === $parse && $wp instanceof \WP && self::candidate( $uri . '/' . $pi );

		if ( class_exists( Swap::class, false ) || ( $hit && ! self::unchanged( $uri, $pi ) ) ) {
			Swap::arm( $wp, $hit );
		}

		return $parse;
	}

	/**
	 * Whether each base segment of a plain path is a registered base its marked language keeps: the swap would
	 * rewrite no byte and send no hop. Escapes, dots, a PATH_INFO other than the path (php -S and some servers copy
	 * it) or no language: false (arm).
	 *
	 * @param string $uri Raw REQUEST_URI.
	 * @param string $pi  Raw PATH_INFO.
	 * @return bool
	 */
	private static function unchanged( string $uri, string $pi ): bool {
		$map  = self::map();
		$path = explode( '?', $uri, 2 )[0];

		if ( ( $pi !== '' && $pi !== $path ) || $map === [] || preg_match( '/[%\x80-\xff.]/', $path ) ) {
			return false;
		}

		$home = self::ctx()['path'];
		$rest = ltrim( $path, '/' );
		$rest = $home !== '' && str_starts_with( $rest . '/', $home . '/' ) ? (string) substr( $rest, strlen( $home ) + 1 ) : $rest;
		// The language as Swap::swap() reads it.
		$lang = self::marker( null, $rest, (string) wp_parse_url( $uri, PHP_URL_QUERY ) )[0] ?? null;

		if ( $lang === null ) {
			return false;
		}

		foreach ( explode( '/', $path ) as $seg ) {
			foreach ( $map['alias'][ $seg ] ?? [] as $entry ) {
				$reg = self::registered( $map, $entry['key'] );

				if ( $reg === '' || implode( '/', (array) $entry['segs'] ) !== $reg || self::canonical( $map, $entry['key'], $lang, $reg ) !== $reg ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * One path segment in map form: bytes >= 0x80 and reserved escapes as lower-case %xx, unreserved escapes
	 * decoded, case kept; '' for a guarded segment (%2F, %5C, controls, dot segments), which never matches.
	 *
	 * @param string $seg Raw segment.
	 * @return string
	 */
	public static function norm( string $seg ): string {
		if ( preg_match( '/[%\x80-\xff]/', $seg ) ) {
			$seg = (string) preg_replace_callback(
				'/%([0-9a-fA-F]{2})|[\x80-\xff]/',
				static function ( array $m ): string {
					$byte = isset( $m[1] ) ? chr( (int) hexdec( $m[1] ) ) : $m[0];

					return preg_match( '/^[A-Za-z0-9._~-]$/', $byte ) ? $byte : '%' . bin2hex( $byte );
				},
				$seg
			);
		}

		return preg_match( '/%(?:2f|5c|[01][0-9a-f]|7f)|[\x00-\x1f\x7f\\\\]/', $seg ) || $seg === '.' || $seg === '..' ? '' : $seg;
	}

	/**
	 * Language a URL marks (as early detection reads it) and the byte length of a path marker.
	 *
	 * @internal Also used by Swap.
	 * @param string|null $host  URL host; null for the request's.
	 * @param string      $rest  Path after the home path, no leading slash.
	 * @param string      $query Query string.
	 * @return array{0: string|null, 1: int}|null Null when the host is not this blog's.
	 */
	public static function marker( ?string $host, string $rest, string $query ): ?array {
		$ctx  = self::ctx();
		$name = $host ?? self::server( 'HTTP_HOST' );
		$lang = $ctx['hosts'][ $name ] ?? $ctx['hosts'][ LanguageRouter::normalize_host( $name ) ] ?? null;

		if ( $lang === null && $host !== null ) {
			return null;
		}

		$lang ??= $ctx['default'];

		if ( $ctx['mode'] === 'subdirectory' ) {
			$first = explode( '/', $rest, 2 )[0];
			$found = self::mark( $first );

			if ( $found !== null ) {
				return [ $found, strlen( $first ) + 1 ];
			}

			$lang = $ctx['hide'] ? $lang : '';
		} elseif ( $ctx['mode'] === 'query' ) {
			parse_str( $query, $args );
			$value = $args[ UrlConverter::query_var() ] ?? '';
			$lang  = is_string( $value ) ? self::mark( $value ) ?? $lang : $lang;
		}

		return [ $lang === '' ? null : $lang, 0 ];
	}

	/**
	 * The base span in $path: at the first segment boundary where an alias entry matches and the bytes before it are
	 * that key's head (eff minus its registered base: front, root), the longest entry.
	 *
	 * @internal Also used by Swap.
	 * @param string      $path Path after home path and language marker, no leading slash.
	 * @param string|null $lang Language of the canonical base; null = no language (registered base).
	 * @return Span|null
	 */
	public static function find( string $path, ?string $lang ): ?array {
		$map   = self::map();
		$segs  = explode( '/', $path );
		$norms = [];
		$start = 0;
		$blog  = get_current_blog_id() . '|';

		if ( $map === [] ) {
			return null;
		}

		for ( $n = 0; $n < self::DEPTH && isset( $segs[ $n ] ); $n++ ) {
			$first  = $segs[ $n ];
			$norm   = self::norm( $first );
			$before = substr( $path, 0, $start );

			foreach ( self::$cands[ $blog . $norm ] ??= self::candidates( $map, $norm ) as [ $value, $head, $entry, $reg ] ) {
				if ( $head !== $before ) {
					continue;
				}

				foreach ( $value as $i => $seg ) {
					// One norm() per segment: up to 16 entries share a first segment.
					if ( $i > 0 && ( $norms[ $n + $i ] ??= self::norm( $segs[ $n + $i ] ?? '' ) ) !== $seg ) {
						continue 2;
					}
				}

				$key = $entry['key'];

				// A key that does not route its bare base (no keys[k].bare) needs a segment after it.
				if ( empty( $map['keys'][ $key ]['bare'] ) && ( $segs[ $n + count( $value ) ] ?? '' ) === '' ) {
					continue;
				}

				$bytes = implode( '/', array_slice( $segs, $n, count( $value ) ) );

				// Pages under the translated shop and the key's shared namespaces keep their own routes, suffixes included.
				if ( ( $map['keys'][ $key ]['exc'] ?? $map['keys'][ $key ]['shr'] ?? null ) && self::kept( $map, $key, $lang, array_slice( $segs, $n + count( $value ) ) ) ) {
					return null;
				}

				return [
					'start'     => $start,
					'len'       => strlen( $bytes ),
					'canonical' => self::canonical( $map, $key, $lang, $reg ),
					'entry'     => $entry,
					'reg'       => $reg,
					'bytes'     => $bytes,
				];
			}

			$start += strlen( $first ) + 1;
		}

		return null;
	}

	/**
	 * Alias entries under one first segment with their key's head (eff minus the registered base); the compiler writes
	 * each bucket longest first, so a nested base is found before its shorter neighbour.
	 *
	 * @param Map    $map   Map.
	 * @param string $first Normalized first segment.
	 * @return array<int, array{0: array<int, string>, 1: string, 2: Entry, 3: string}>
	 */
	private static function candidates( array $map, string $first ): array {
		$out = [];

		foreach ( $map['alias'][ $first ] ?? [] as $entry ) {
			$reg = self::registered( $map, $entry['key'] );

			if ( $reg !== '' ) {
				$out[] = [ explode( '/', implode( '/', (array) $entry['segs'] ) ), substr( $map['keys'][ $entry['key'] ]['eff'] ?? '', 0, -strlen( $reg ) ), $entry, $reg ];
			}
		}

		return $out;
	}

	/**
	 * URL facts of the current blog: mode, default language, hidden default, hosts by language, home path.
	 *
	 * @internal Also used by Swap.
	 * @return Ctx
	 */
	public static function ctx(): array {
		$blog = get_current_blog_id();

		if ( ! isset( self::$ctx[ $blog ] ) ) {
			$router   = Plugin::get_instance()->router();
			$settings = Plugin::get_instance()->settings();
			$home     = (array) wp_parse_url( self::str( get_option( 'home' ) ) );
			$mode     = $settings->get_url_mode();
			$default  = self::str( $router->get_default_language()->slug ?? '' );
			$host     = LanguageRouter::normalize_host( $home['host'] ?? '' );
			$hosts    = [ $host => $default ];

			foreach ( in_array( $mode, [ 'subdomain', 'domain' ], true ) ? $router->get_active_languages() : [] as $language ) {
				$slug = self::str( $language->slug ?? '' );
				$name = LanguageRouter::normalize_host( $mode === 'domain' ? $settings->get_language_domain( $slug ) : $slug . '.' . $host );

				if ( $name !== '' ) {
					$hosts += [ $name => $slug ];
				}
			}

			self::$ctx[ $blog ] = [
				'mode'    => $mode,
				'hide'    => $settings->hide_default_prefix(),
				'default' => $default,
				'hosts'   => $hosts,
				'path'    => trim( $home['path'] ?? '', '/' ),
				'r'       => [ $router, 'slug_for_path_prefix' ],
				'm'       => [],
			];
		}

		return self::$ctx[ $blog ];
	}

	/**
	 * Language slug of a path or query marker, through the router's prefix map (memoized per blog).
	 *
	 * @internal Also used by Swap.
	 * @param string $segment Marker value.
	 * @return string|null
	 */
	public static function mark( string $segment ): ?string {
		self::ctx();
		$ctx = &self::$ctx[ get_current_blog_id() ];

		if ( ! array_key_exists( $segment, $ctx['m'] ) ) {
			$ctx['m'][ $segment ] = ( $ctx['r'] )( $segment );
		}

		return $ctx['m'][ $segment ];
	}

	/**
	 * Replace the base span of a URL of this blog with the canonical base of $lang (default: the URL's marker), or
	 * with the registered base.
	 *
	 * @param string      $url        Absolute or root-relative URL.
	 * @param string|null $lang       Target language.
	 * @param bool        $registered Write the registered base.
	 * @return string
	 */
	private static function rewrite( string $url, ?string $lang, bool $registered ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['path'] ) || ! self::candidate( $parts['path'] ) ) {
			return $url;
		}

		$path = $parts['path'];
		$home = self::ctx()['path'];
		$rest = ltrim( $path, '/' );

		if ( $home !== '' && ! str_starts_with( $rest . '/', $home . '/' ) ) {
			return $url;
		}

		$rest = $home === '' ? $rest : (string) substr( $rest, strlen( $home ) + 1 );
		$mark = self::marker( $parts['host'] ?? null, $rest, $parts['query'] ?? '' );
		$rest = (string) substr( $rest, $mark[1] ?? 0 );
		$span = $mark === null ? null : self::find( $rest, $lang ?? $mark[0] );
		$with = $registered ? $span['reg'] ?? '' : $span['canonical'] ?? '';

		if ( $span === null || $with === $span['bytes'] ) {
			return $url;
		}

		$at = (int) strpos( $url, $path, (int) strpos( $url, $parts['host'] ?? '' ) );

		return substr_replace( $url, $with, $at + strlen( $path ) - strlen( $rest ) + $span['start'], $span['len'] );
	}

	/**
	 * Whether the path after a base starts with one of the key's shop child URIs of the language or its shared
	 * namespaces (segment-bounded).
	 *
	 * @param Map                $map  Map.
	 * @param string             $key  Base key.
	 * @param string|null        $lang Language.
	 * @param array<int, string> $segs Raw segments after the base.
	 * @return bool
	 */
	private static function kept( array $map, string $key, ?string $lang, array $segs ): bool {
		$first = self::norm( $segs[0] ?? '' );

		foreach ( [ $map['keys'][ $key ]['exc'][ (string) $lang ][ $first ] ?? [], $map['keys'][ $key ]['shr'][ $first ] ?? [] ] as $uris ) {
			foreach ( $uris as $uri ) {
				if ( str_starts_with( implode( '/', array_map( [ self::class, 'norm' ], $segs ) ) . '/', $uri . '/' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Canonical base of (key, language): the translated value when active, on and allowed, else the registered base.
	 *
	 * @param Map         $map  Map.
	 * @param string      $key  Base key.
	 * @param string|null $lang Language.
	 * @param string      $reg  Registered base.
	 * @return string
	 */
	private static function canonical( array $map, string $key, ?string $lang, string $reg ): string {
		$blog = get_current_blog_id();
		$memo = $blog . '|' . $key . '|' . $lang;

		if ( ! isset( self::$memo[ $memo ] ) ) {
			$tr = $lang === null ? '' : ( $map['tr'][ $key ][ $lang ] ?? '' );

			/**
			 * Whether a translated URL base is canonical; a function of these arguments only (links, redirects and
			 * the canonical tag read the same answer).
			 *
			 * @hook perflocale/url_bases/enabled
			 * @since 1.0.8
			 * @param bool   $on      Translated base on.
			 * @param string $key     Base key: pt:<post_type>, pta:<post_type> or tx:<taxonomy>.
			 * @param string $lang    Language slug.
			 * @param int    $blog_id Blog id.
			 */
			$on                  = $tr !== '' && $map['state'] === 'active' && empty( $map['off'][ $key ] ) && (bool) apply_filters( 'perflocale/url_bases/enabled', true, $key, (string) $lang, $blog );
			self::$memo[ $memo ] = $on ? $tr : $reg;
		}

		return self::$memo[ $memo ];
	}

	/**
	 * Registered base of a key: its `registered` alias entry, a suffix of eff (alias values exclude front and root).
	 * Called only while preparing candidates (cached per first segment).
	 *
	 * @param Map    $map Map.
	 * @param string $key Base key.
	 * @return string '' when the map has none (the key is then never matched).
	 */
	private static function registered( array $map, string $key ): string {
		$eff = $map['keys'][ $key ]['eff'] ?? '';

		foreach ( explode( '/', $eff ) as $seg ) {
			foreach ( $map['alias'][ $seg ] ?? [] as $entry ) {
				$reg = implode( '/', (array) $entry['segs'] );

				if ( $entry['key'] === $key && $entry['kind'] === 'registered' && str_ends_with( '/' . $eff, '/' . $reg ) ) {
					return $reg;
				}
			}
		}

		return '';
	}

	/**
	 * A request server value as a string ('' when absent): raw bytes on purpose, core matches these.
	 *
	 * @internal Also used by the cold classes.
	 * @param string $key $_SERVER key.
	 * @return string
	 */
	public static function server( string $key ): string {
		return self::str( $_SERVER[ $key ] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * A string value, or ''.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function str( $value ): string {
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Whether some segment of a raw path is a first segment of the alias index.
	 *
	 * @param string $path Raw path.
	 * @return bool
	 */
	private static function candidate( string $path ): bool {
		$alias = self::map()['alias'] ?? [];
		$plain = ! preg_match( '/[%\x80-\xff.]/', $path );

		// A raw path also has its leading '', home path and language marker before find()'s segments.
		foreach ( explode( '/', $path, self::DEPTH + 8 ) as $seg ) {
			if ( isset( $alias[ $plain ? $seg : self::norm( $seg ) ] ) ) {
				return true;
			}
		}

		return false;
	}
}
