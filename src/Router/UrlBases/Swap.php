<?php
/**
 * Parse-time base swap: arm, commit, restore (DESIGN-step1 3.4).
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cold: loaded only by a request whose raw path holds a base segment it may change. Only core's rule-matching loop
 * sees the routed base in REQUEST_URI; $wp->request keeps it until parse_request:2 puts the requested base back.
 *
 * @phpstan-import-type Span from Runtime
 * @phpstan-import-type Hit from Runtime
 * @since 1.0.8
 */
final class Swap {

	/**
	 * Own hooks registered.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Armed parse: environment and hook stack at arm.
	 *
	 * @var array{wp: \WP, stack: array<int, string>}|null
	 */
	private static ?array $arm = null;

	/**
	 * Swap of one parse: frame id, raw values, selected path before/after, span offset in it, eff, restored.
	 *
	 * @var array{id: int, uri: string, pi: ?string, old: string, new: string, cut: int, eff: string, done: bool}|null
	 */
	private static ?array $state = null;

	/**
	 * Base span of the main request in raw REQUEST_URI bytes, with the key's group.
	 *
	 * @var Hit|null
	 */
	private static ?array $span = null;

	/**
	 * Probe value.
	 *
	 * @var string
	 */
	private static string $route = '';

	/**
	 * Arm for this parse of the main environment, or drop its stale arm. A parse nested inside the matching window is
	 * never armed; a parse of another WP object neither arms nor drops the main one (it routes as without URL bases).
	 *
	 * @param mixed $wp  Environment about to be parsed.
	 * @param bool  $hit Its raw path holds a base segment.
	 * @return void
	 */
	public static function arm( $wp, bool $hit ): void {
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_filter( 'option_rewrite_rules', [ self::class, 'commit' ], PHP_INT_MAX );
			add_filter( 'rewrite_rules_array', [ self::class, 'commit' ], PHP_INT_MAX );
			add_filter( 'query_vars', [ self::class, 'restore' ], PHP_INT_MIN );
			add_action( 'parse_request', [ self::class, 'parsed' ], 2 );

			// Fallback restore for the recorded frame; template_redirect only drops a stale arm.
			foreach ( [ 'wp_headers', 'send_headers', 'wp', 'template_redirect' ] as $hook ) {
				add_filter( $hook, [ self::class, 'drop' ], PHP_INT_MIN, 2 );
			}
		}

		if ( $wp !== ( $GLOBALS['wp'] ?? null ) ) {
			return;
		}

		if ( ! $hit || ! $wp instanceof \WP || ( self::$state !== null && ! self::$state['done'] ) ) {
			self::hold( null );

			return;
		}

		self::hold(
			[
				'wp'    => $wp,
				'stack' => self::stack(),
			]
		);
	}

	/**
	 * Set or clear the arm; pre_option is hooked only while armed (it runs on every option read).
	 *
	 * @param array{wp: \WP, stack: array<int, string>}|null $arm Arm.
	 * @return void
	 */
	private static function hold( ?array $arm ): void {
		if ( $arm === null && self::$arm !== null ) {
			remove_filter( 'pre_option', [ self::class, 'pre' ], PHP_INT_MAX );
		} elseif ( $arm !== null && self::$arm === null ) {
			add_filter( 'pre_option', [ self::class, 'pre' ], PHP_INT_MAX, 2 );
		}

		self::$arm = $arm;
	}

	/**
	 * Pre_option (PHP_INT_MAX, while armed): a short-circuited rewrite_rules read never reaches option_rewrite_rules,
	 * so the commit runs on the value get_option() returns; false goes on to option_rewrite_rules.
	 *
	 * @param mixed $pre    Short-circuit value.
	 * @param mixed $option Option name.
	 * @return mixed
	 */
	public static function pre( $pre, $option = '' ) {
		return $pre !== false && $option === 'rewrite_rules' ? self::commit( $pre ) : $pre;
	}

	/**
	 * The rules core is about to match, right before it reads REQUEST_URI (option_rewrite_rules, pre(), or
	 * rewrite_rules_array when the parse regenerates them): commit only inside the armed parse. The frame proof decides.
	 * Empty rules keep the arm (core regenerates them); an open swap is never committed again.
	 *
	 * @param mixed $rules Rules, returned unchanged.
	 * @return mixed
	 */
	public static function commit( $rules ) {
		$arm = self::$arm;

		if ( $arm === null || ! is_array( $rules ) || $rules === [] || ( self::$state !== null && ! self::$state['done'] ) || doing_filter( 'do_parse_request' ) || $arm['wp']->query_vars !== [] || self::stack() !== $arm['stack'] || ! self::framed( $arm['wp'] ) ) {
			return $rules;
		}

		self::hold( null );
		self::$route = self::swap( $arm['wp'], $rules );

		if ( str_starts_with( self::$route, 'unswapped' ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'PerfLocale url bases: ' . self::$route ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $rules;
	}

	/**
	 * Query_vars (PHP_INT_MIN; once per parse, after rule matching): the raw REQUEST_URI and PATH_INFO back
	 * (PATH_INFO unset again when it was absent).
	 *
	 * @param mixed $vars Public query vars, unchanged.
	 * @return mixed
	 */
	public static function restore( $vars = null ) {
		if ( self::$state === null || self::$state['done'] ) {
			return $vars;
		}

		$_SERVER['REQUEST_URI'] = self::$state['uri'];

		if ( self::$state['pi'] === null ) {
			unset( $_SERVER['PATH_INFO'] );
		} else {
			$_SERVER['PATH_INFO'] = self::$state['pi'];
		}

		self::$state['done'] = true;

		return $vars;
	}

	/**
	 * Parse_request:2: the requested base back in $wp->request. A matched rule outside eff/ drops the span (no base
	 * redirect, no K3b); else an alias registers the base rule.
	 *
	 * @param mixed $wp Environment.
	 * @return void
	 */
	public static function parsed( $wp ): void {
		$state = self::$state;

		if ( $state === null || ! $wp instanceof \WP || spl_object_id( $wp ) !== $state['id'] ) {
			return;
		}

		self::restore();
		self::$state = null;
		$request     = (string) $wp->request;
		$cut         = strlen( $state['new'] ) - strlen( $request );

		// Only a front strip (the language prefix) may have happened since the match.
		if ( $cut >= 0 && $cut <= $state['cut'] && substr( $state['new'], $cut ) === $request ) {
			$wp->request = (string) substr( $state['old'], $cut );
		}

		$rule = (string) $wp->matched_rule;

		if ( $rule !== '' && ! self::own_rule( $rule, $state['eff'] ) ) {
			self::$route = 'foreign_rule';
			self::$span  = null;

			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'PerfLocale url bases: swapped request matched ' . $rule ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		} elseif ( self::$span !== null && self::$span['kind'] !== 'current' ) {
			add_action( 'template_redirect', [ AliasRedirect::class, 'run' ], 12 );
		}
	}

	/**
	 * Whether a rule is under the key's base. Segment-bounded: eff product owns product/..., not products/...; a
	 * language-prefixed copy counts.
	 *
	 * @param string $rule Rule regex.
	 * @param string $eff  Effective base.
	 * @return bool
	 */
	public static function own_rule( string $rule, string $eff ): bool {
		$rule = rawurldecode( $rule );
		$eff  = rawurldecode( $eff ) . '/';

		return str_starts_with( $rule, $eff ) || str_starts_with( (string) preg_replace( '#^\([^)]*\)/#', '', $rule ), $eff );
	}

	/**
	 * Drop a stale arm; restore when the hook's environment is the recorded frame.
	 *
	 * @param mixed $value Headers (wp_headers) or the environment.
	 * @param mixed $wp    Environment (wp_headers).
	 * @return mixed
	 */
	public static function drop( $value = null, $wp = null ) {
		self::hold( null );
		$wp = $value instanceof \WP ? $value : $wp;

		if ( $wp instanceof \WP && self::$state !== null && spl_object_id( $wp ) === self::$state['id'] ) {
			self::restore();
		}

		return $value;
	}

	/**
	 * Runtime::locate_base_span(): the span at the start of a base-relative path, with its alias kind.
	 *
	 * @param string $rel Path after home and language marker.
	 * @return array<string, int|string>|null
	 */
	public static function locate( string $rel ): ?array {
		$lang = \PerfLocale\Plugin::get_instance()->router()->get_current_slug();
		$lang = $lang === '' ? null : $lang;
		$span = Runtime::find( ltrim( $rel, '/' ), $lang );

		if ( $span === null ) {
			return null;
		}

		return [
			'start'     => $span['start'],
			'len'       => $span['len'],
			'canonical' => $span['canonical'],
			'kind'      => self::kind( $span, $lang ),
			'key'       => $span['entry']['key'],
			'lang'      => (string) $lang,
		];
	}

	/**
	 * Why a stored map is not used, for Site Health: '' when usable, else absent, invalid or unverified_routes.
	 *
	 * @param mixed $map Stored option value.
	 * @return string
	 */
	public static function map_problem( $map ): string {
		if ( Runtime::usable( $map ) ) {
			return '';
		}

		if ( ! is_array( $map ) ) {
			return $map === false ? 'absent' : 'invalid';
		}

		return count( (array) ( $map['unv'] ?? [] ) ) > 16 ? 'unverified_routes' : 'invalid';
	}

	/**
	 * Alias kind of a span: current when its value is canonical; a non-canonical current value is another
	 * language's, or this language's former one (history).
	 *
	 * @param Span        $span Span from Runtime::find().
	 * @param string|null $lang Language the span was found for.
	 * @return string
	 */
	private static function kind( array $span, ?string $lang ): string {
		if ( implode( '/', (array) $span['entry']['segs'] ) === $span['canonical'] ) {
			return 'current';
		}

		$kind = $span['entry']['kind'];

		return $kind !== 'current' ? $kind : ( $span['entry']['lang'] === $lang ? 'history' : 'other_language' );
	}

	/**
	 * The main request's span.
	 *
	 * @return Hit|null
	 */
	public static function span(): ?array {
		return self::$span;
	}

	/**
	 * Probe value.
	 *
	 * @return string
	 */
	public static function route(): string {
		return self::$route;
	}

	/**
	 * REQUEST_URI as received.
	 *
	 * @return string
	 */
	public static function raw(): string {
		return self::$state !== null && ! self::$state['done'] ? self::$state['uri'] : Runtime::server( 'REQUEST_URI' );
	}

	/**
	 * Coordinates computed as core will (class-wp.php:162-205): selected path, its offset in the raw source, the
	 * language segment when home_url() is unprefixed, then precedence, provenance and the swap.
	 *
	 * @param \WP                 $wp    Environment being parsed.
	 * @param array<mixed, mixed> $rules Stored rules core is about to match.
	 * @return string Probe value.
	 */
	private static function swap( \WP $wp, array $rules ): string {
		$map  = Runtime::map();
		$ctx  = Runtime::ctx();
		$uri  = Runtime::server( 'REQUEST_URI' );
		$pi   = Runtime::server( 'PATH_INFO' );
		$path = explode( '?', $uri, 2 )[0];
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$info = $pi !== '';
		$src  = $info ? explode( '?', $pi, 2 )[0] : $path;
		$base = $info ? strpos( $path, $src ) : 0;
		$pre  = '';

		if ( $map === [] ) {
			return '';
		}

		// Core turns % into %25 in PATH_INFO and str_replace()s it out of REQUEST_URI: both must hold the same bytes.
		if ( $base === false || ( $info && str_contains( $src, '%' ) ) ) {
			return 'unswapped:unlocatable';
		}

		if ( $info ) {
			$file = self::strip( str_replace( $src, '', $path ), $home )[0];
			$pre  = $file !== '' ? $file . '/' : '';
		}

		[ $sel, $off ] = self::strip( $src, $home );

		if ( $sel === '' || ( $info ? preg_match( '|^.*index.php$|', $sel ) : $sel === 'index.php' ) ) {
			return '';
		}

		$rest = ltrim( $src, '/' );
		$rest = $ctx['path'] !== '' && str_starts_with( $rest . '/', $ctx['path'] . '/' ) ? (string) substr( $rest, strlen( $ctx['path'] ) + 1 ) : $rest;
		$lang = Runtime::marker( null, $rest, (string) wp_parse_url( $uri, PHP_URL_QUERY ) )[0] ?? null;
		$lead = 0;
		$top  = explode( '/', $sel, 2 )[0];
		$last = substr( (string) strrchr( '/' . $home, '/' ), 1 );

		// A language segment is in the selected path only when home_url() was not prefixed.
		if ( $ctx['mode'] === 'subdirectory' && Runtime::mark( $last ) === null && Runtime::mark( $top ) !== null ) {
			$lead = strlen( $top ) + 1;
		}

		$rel  = $pre . substr( $sel, $lead );
		$span = Runtime::find( $rel, $lang );

		if ( $span === null ) {
			return '';
		}

		// An off key on a page URI (map pgs): a numbered page suffix after it (comment-page-N, /N) is the page's own.
		$after = (string) substr( $rel, $span['start'] + $span['len'] );

		foreach ( '' !== $after ? $map['keys'][ $span['entry']['key'] ]['pgs'] ?? [] : [] as $tail ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A stored rule's tail; a malformed one is skipped, as core does.
			if ( 1 === @preg_match( '#^' . $tail . '#', $after ) ) {
				return 'page_suffix';
			}
		}

		$cut = $lead + $span['start'] - strlen( $pre );
		$at  = $off + $cut;

		if ( $cut < $lead || substr( $src, $at, $span['len'] ) !== $span['bytes'] ) {
			return 'unswapped:unlocatable';
		}

		// Precedence (Q17): an unverifiable plugin rule that matches the original path keeps its route.
		$tests = [ $sel, (string) substr( $sel, $lead ), $pre . $sel ];
		$tests = array_unique( array_merge( $tests, array_map( 'urldecode', $tests ) ) );

		foreach ( (array) ( $map['unv'] ?? [] ) as $re ) {
			foreach ( $tests as $test ) {
				if ( preg_match( '#^' . $re . '#', $test ) ) {
					return 'unswapped:precedence';
				}
			}
		}

		// Provenance: the stored rules still route this key's own rule (else the map is stale for it).
		$own = (array) ( $map['own'][ $span['entry']['key'] ] ?? [] );
		$re  = array_key_first( $own );

		if ( $re === null || ( $rules[ $re ] ?? null ) !== $own[ $re ] ) {
			return 'unswapped:provenance';
		}

		// Rules stored since the compile (OQ1, three O(1) reads): a newer route of the original path keeps it.
		$rg = $map['rg'] ?? null;

		$eff = (string) ( $map['keys'][ $span['entry']['key'] ]['eff'] ?? '' );

		if ( is_string( $rg ) && $rg !== count( $rules ) . '|' . array_key_first( $rules ) . '|' . array_key_last( $rules )
			&& Generation::keeps( $rules, $tests, $eff, substr( $eff, 0, -strlen( $span['reg'] ) ) ) ) {
			return 'unswapped:generation';
		}

		self::$state = [
			'id'   => spl_object_id( $wp ),
			'uri'  => $uri,
			'pi'   => isset( $_SERVER['PATH_INFO'] ) ? $pi : null,
			'old'  => $sel,
			'new'  => substr_replace( $sel, $span['reg'], $cut, $span['len'] ),
			'cut'  => $cut,
			'eff'  => $eff,
			'done' => false,
		];

		$_SERVER['REQUEST_URI'] = substr_replace( $uri, $span['reg'], (int) $base + $at, $span['len'] );

		if ( $info ) {
			$_SERVER['PATH_INFO'] = substr_replace( $pi, $span['reg'], $at, $span['len'] );
		}

		// Only the main environment is armed (arm()), so this is the main request's span.
		self::$span = [
			'start'     => (int) $base + $at,
			'len'       => $span['len'],
			'canonical' => $span['canonical'],
			'kind'      => self::kind( $span, $lang ),
			'key'       => $span['entry']['key'],
			'lang'      => (string) $lang,
			'grp'       => (string) ( $map['keys'][ $span['entry']['key'] ]['grp'] ?? 'core' ),
			'bytes'     => $span['bytes'],
		];

		return 'swapped';
	}

	/**
	 * Core's front strips of one source: slashes, then the filtered home path (case-insensitive, not segment-bounded).
	 *
	 * @param string $src  REQUEST_URI path or PATH_INFO.
	 * @param string $home Home path, trimmed.
	 * @return array{0: string, 1: int} Value and its byte offset in $src.
	 */
	private static function strip( string $src, string $home ): array {
		$value = ltrim( $src, '/' );

		if ( $home !== '' && preg_match( '|^' . preg_quote( $home, '|' ) . '|i', $value, $m ) ) {
			$value = ltrim( (string) substr( $value, strlen( $m[0] ) ), '/' );
		}

		return [ rtrim( $value, '/' ), strlen( $src ) - strlen( $value ) ];
	}

	/**
	 * Frame proof: this rules read is WP_Rewrite::wp_rewrite_rules() called by WP::parse_request() on the armed
	 * object (searched: an extra frame may sit between). Unknown shape: false, so no swap.
	 *
	 * @param \WP $wp Armed environment.
	 * @return bool
	 */
	private static function framed( \WP $wp ): bool {
		$trace = debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 10 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace

		foreach ( $trace as $i => $frame ) {
			if ( $frame['function'] === 'wp_rewrite_rules' && ( $frame['object'] ?? null ) instanceof \WP_Rewrite ) {
				return ( $trace[ $i + 1 ]['function'] ?? '' ) === 'parse_request' && ( $trace[ $i + 1 ]['object'] ?? null ) === $wp;
			}
		}

		return false;
	}

	/**
	 * Hook stack without its innermost entry.
	 *
	 * @return array<int, string>
	 */
	private static function stack(): array {
		global $wp_current_filter;

		return array_slice( array_values( array_filter( is_array( $wp_current_filter ) ? $wp_current_filter : [], 'is_string' ) ), 0, -1 );
	}
}
