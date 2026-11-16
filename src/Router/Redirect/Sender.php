<?php
/**
 * The one path every PerfLocale front-end redirect is sent through.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\Redirect;

use PerfLocale\Plugin;
use PerfLocale\Router\LanguageRouter;
use PerfLocale\Router\UrlConverter;
use PerfLocale\Router\UrlBases\Runtime;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates, filters, guards and sends one PerfLocale redirect per request.
 *
 * Cold: loaded only when a rule decided to redirect.
 *
 * @since 1.0.8
 */
final class Sender {

	/**
	 * Validation or a guard refused the target.
	 */
	public const REFUSED = 1;

	/**
	 * Not sent: filter cancel, action request, excluded path, method, latch, self or already refused target.
	 */
	public const DECLINED = 2;

	/**
	 * Seconds a browser keeps a temporary missing-translation hop.
	 */
	private const FALLBACK_MAX_AGE = 300;

	/**
	 * Visitor-dependent reasons: temporary status and no-store.
	 */
	public const VISITOR = [ 'browser', 'edge_hint', 'geo' ];

	/**
	 * Rule method per reason (context `rule`).
	 */
	private const RULES = [
		'prefix_form'              => 'maybe_canonicalise_prefix_form',
		'sentinel_clean'           => 'clean_redirect_sentinel',
		'default_to_prefix'        => 'maybe_redirect_default_to_prefix',
		'prefix_to_default'        => 'maybe_redirect_prefix_to_default',
		'browser'                  => 'maybe_redirect_browser',
		'edge_hint'                => 'maybe_redirect_edge_hint',
		'renamed_query_slug'       => 'maybe_redirect_renamed_query_slug',
		'geo'                      => 'maybe_redirect',
		'term_slug'                => 'redirect_term_to_translated_slug',
		'term_slug_history'        => 'redirect_term_to_translated_slug',
		'front_page_slug'          => 'redirect_front_page_slug',
		'own_sibling'              => 'redirect_to_own_language_sibling',
		'fallback'                 => 'redirect_to_fallback',
		'missing_redirect_default' => 'redirect_to_fallback',
		'fallback_sentinel_clean'  => 'clean_fallback_sentinel',
		'renamed_code'             => 'redirect',
		'base_alias'               => 'redirect',
	];

	/**
	 * Query keys that mark a core or WooCommerce action or credential: any of them blocks a redirect.
	 */
	public const ACTION_ARGS = [
		'preview_nonce',
		'_wpnonce',
		'action',
		'reset-token',
		'show-reset-form',
		'wc-resend-set-password',
		'add-to-cart',
		'remove_item',
		'undo_item',
		'update_cart',
		'proceed',
		'apply_coupon',
		'remove_coupon',
		'order_again',
		'cancel_order',
		'pay_for_order',
		'wc-ajax',
		'wc-api',
	];

	/**
	 * Longest Location sent, in bytes.
	 */
	private const MAX_LENGTH = 2048;

	/**
	 * A Location was sent in this request.
	 *
	 * @var bool
	 */
	private static bool $sent = false;

	/**
	 * PerfLocale's own wp_redirect() call is running.
	 *
	 * @var bool
	 */
	private static bool $sending = false;

	/**
	 * A to() call is in progress.
	 *
	 * @var bool
	 */
	private static bool $planning = false;

	/**
	 * Normalized targets refused by validation or a guard in this request.
	 *
	 * @var array<string, true>
	 */
	private static array $refused = [];

	/**
	 * Host-mode path keys of hops declined or refused in this request (no scheme, no query).
	 *
	 * @var array<string, true>
	 */
	private static array $declined = [];

	/**
	 * The send in progress: Location as emitted, its absolute form, status, visitor flag, who changed it.
	 *
	 * @var array{emit: string, abs: string, status: int, visitor: bool, changed: string}|null
	 */
	private static ?array $plan = null;

	/**
	 * Allowed origins per blog id.
	 *
	 * @var array<int, array<string, array<int|string, true>>>
	 */
	private static array $origins = [];

	/**
	 * The raw `home` option parsed, per blog id (memoized as the origins are; watch() drops both).
	 *
	 * @var array<int, array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string}>
	 */
	private static array $home = [];

	/**
	 * Send one PerfLocale redirect and exit; returns only when nothing was sent.
	 *
	 * @param string                                                         $target Location (absolute, or root-relative to keep the caller's bytes).
	 * @param int                                                            $status Planned status.
	 * @param string                                                         $reason Reason code (5.1).
	 * @param array{cookie?: string, after?: array{0: string, 1?: array<int, mixed>}, dest?: int, fb?: array{0: string, 1: string, 2: int, 3: int}} $opts Visitor cookie slug; action fired after a successful send; destination post id when the rule changes the object; fallback status filter arguments (from, to, from post, to post; to post 0 is the homepage).
	 * @return int REFUSED or DECLINED.
	 */
	public static function to( string $target, int $status, string $reason, array $opts = [] ): int {
		// A callback of this call (any filter, not only perflocale/redirect) calling to() again would recurse.
		if ( self::$planning ) {
			return self::DECLINED;
		}

		self::$planning = true;

		try {
			if ( isset( $opts['fb'] ) ) {
				$status = self::fallback_status( $status, $opts['fb'] );

				if ( $opts['fb'][3] === 0 ) {
					$opts['dest'] = 0;
				}
			}

			$result = self::attempt( $target, $status, $reason, $opts );
		} finally {
			self::$planning = false;
		}

		// Not sent: withdraw the language cookie detection queued, so a later page can still redirect this visitor.
		if ( in_array( $reason, self::VISITOR, true ) && ! self::$sending ) {
			self::forget_language_cookie();
		}

		return $result;
	}

	/**
	 * Steps 1-12 of one send; exits on success.
	 *
	 * @param string                                                                            $target Location.
	 * @param int                                                                               $status Planned status.
	 * @param string                                                                            $reason Reason code.
	 * @param array{cookie?: string, after?: array{0: string, 1?: array<int, mixed>}, dest?: int, fb?: array{0: string, 1: string, 2: int, 3: int}} $opts As to().
	 * @return int REFUSED or DECLINED.
	 */
	private static function attempt( string $target, int $status, string $reason, array $opts ): int {
		$visitor = in_array( $reason, self::VISITOR, true );

		if ( self::$sent || self::$sending || headers_sent() || ! self::front_end() ) {
			return self::DECLINED;
		}

		// GET/HEAD only, every reason: a redirect drops a POST body.
		if ( ! self::get_or_head() ) {
			return self::DECLINED;
		}

		// No missing-translation hop from a fallback landing, signed or not: no loop, whatever rewrote its URL. A signed
		// landing never asks (PostQueryFilter returns first), so this marker is typed: noindex the duplicate it serves.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $opts['fb'] ) && ! empty( $_GET['perflocale_fb'] ) ) {
			header( 'X-Robots-Tag: noindex', false );

			return self::DECLINED;
		}

		$loc = self::recheck( self::resolve( $target ) );

		if ( $loc === null ) {
			return self::DECLINED;
		}

		if ( self::action_request() || self::excluded_request() ) {
			self::hold( $loc['abs'] );

			return self::DECLINED;
		}

		$private = $visitor || self::no_store_required() || self::cookie_dependent( $reason );
		$planned = $status;
		$changed = '';
		$context = self::context( $reason, $loc['abs'], $visitor );

		/**
		 * Change or cancel a PerfLocale redirect before it is sent.
		 *
		 * The target can carry the visitor's query args; a changed target must be canonical. A cancel or change
		 * makes the response no-store unless the returned array has `url_only => true`. A callback that decides by
		 * the visitor returns `cache => 'private'` on every branch, so a shared cache never stores its unchanged hop.
		 *
		 * @hook perflocale/redirect
		 * @since 1.0.8
		 * @param array|false $redirect { target: string, status: int, reason: string, cache: 'public'|'private' }; a returned `private` only strengthens.
		 * @param array       $context  Request facts; return url_only => true only when a change depends on the URL alone.
		 */
		$filtered = apply_filters(
			'perflocale/redirect',
			[
				'target' => $loc['abs'],
				'status' => $status,
				'reason' => $reason,
				'cache'  => $private ? 'private' : 'public',
			],
			$context
		);

		if ( false === $filtered ) {
			CacheHeaders::require_no_store();
			self::hold( $loc['abs'] );

			return self::DECLINED;
		}

		// A callback returning anything but an array or false changes nothing.
		$filtered = self::as_array( $filtered );
		$url_only = ! empty( $filtered['url_only'] );

		if ( ! $private && 'private' === ( $filtered['cache'] ?? '' ) ) {
			CacheHeaders::require_no_store();
			$private = true;
		}

		if ( ! empty( $filtered['cancel'] ) ) {
			if ( ! $url_only ) {
				CacheHeaders::require_no_store();
			}

			self::hold( $loc['abs'] );

			return self::DECLINED;
		}

		// The planned hop: held as well when a swapped target is not sent, else core's canonical sends it.
		$first      = $loc['abs'];
		$new_target = isset( $filtered['target'] ) && is_string( $filtered['target'] ) ? $filtered['target'] : $loc['abs'];
		$new_status = isset( $filtered['status'] ) && is_numeric( $filtered['status'] ) ? (int) $filtered['status'] : $status;

		if ( $new_target !== $loc['abs'] || $new_status !== $status ) {
			// Private first: every early return of the re-check below must already be no-store.
			if ( ! $url_only ) {
				CacheHeaders::require_no_store();
				$private = true;
			}

			$changed = 'filter';
			$status  = $new_status;

			if ( $new_target !== $loc['abs'] ) {
				$loc = self::recheck( self::resolve( $new_target ) );

				if ( $loc === null ) {
					self::hold( $first );

					return self::DECLINED;
				}

				$context = self::context( $reason, $loc['abs'], $visitor );
			}
		}

		$status = self::status( $status, $planned, $visitor );

		if ( class_exists( 'WooCommerce', false ) && 'yes' === get_option( 'woocommerce_force_ssl_checkout' ) ) {
			$loc = self::with_abs( $loc, WooScheme::select( $loc['abs'], $opts ) );
		}

		$why = self::invalid( $loc['emit'], $loc['abs'] );

		if ( $why !== '' ) {
			return self::refuse( $loc['abs'], $reason, $why, $first );
		}

		$plan       = [
			'emit'    => $loc['emit'],
			'abs'     => $loc['abs'],
			'status'  => $status,
			'visitor' => $visitor,
			'changed' => $changed,
		];
		self::$plan = $plan;

		if ( $private ) {
			CacheHeaders::require_no_store();
		}

		$host = self::allow_host( $loc['abs'] );

		self::arm();
		self::$sending = true;

		try {
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Target checked by invalid() above; never wp_safe_redirect(): its admin_url() fallback would turn a refusal into a hop.
			$ok = wp_redirect( $loc['emit'], $status, 'PerfLocale' );
		} finally {
			self::$sending = false;
			$plan          = self::$plan ?? $plan;
			self::$plan    = null;
			self::disarm( $host );
		}

		if ( ! $ok ) {
			header_remove( 'Location' );

			return self::refuse( $loc['abs'], $reason, 'wp_redirect', $first );
		}

		if ( ! self::post_send_ok( $plan['emit'], $plan['status'] ) ) {
			header_remove( 'Location' );
			header_remove( 'X-Redirect-By' );
			status_header( 200 );
			CacheHeaders::require_no_store();

			return self::refuse( $loc['abs'], $reason, 'post_send', $first );
		}

		self::finish( $plan, $loc['abs'], $context, $opts, $private );

		exit;
	}

	/**
	 * Step 12 (a)-(d): latch, cookie, actions, then the seal of Location, status and cache headers.
	 *
	 * @param array{emit: string, abs: string, status: int, visitor: bool, changed: string} $plan    Accepted send.
	 * @param string                                                                         $abs     Absolute form the context describes.
	 * @param array<string, mixed>                                                           $context Filter context.
	 * @param array{cookie?: string, after?: array{0: string, 1?: array<int, mixed>}, dest?: int, fb?: array{0: string, 1: string, 2: int, 3: int}} $opts As to().
	 * @param bool                                                                           $private No-store class.
	 * @return void
	 */
	private static function finish( array $plan, string $abs, array $context, array $opts, bool $private ): void {
		self::$sent = true;

		if ( $plan['visitor'] && isset( $opts['cookie'] ) ) {
			$router = self::router();

			if ( $router !== null ) {
				$router->set_language_cookie_public( $opts['cookie'] );
			}
		}

		if ( $plan['changed'] !== '' ) {
			$context['changed_by'] = $plan['changed'];

			if ( $plan['abs'] !== $abs ) {
				$context = array_merge( $context, self::target_facts( $plan['abs'] ) );
			}
		}

		/**
		 * Fires after PerfLocale sent a redirect, with the Location and status that left.
		 *
		 * @hook perflocale/redirected
		 * @since 1.0.8
		 * @param string $target  Location sent.
		 * @param int    $status  Status sent.
		 * @param array  $context The perflocale/redirect context; `changed_by` is `filter` or `wp_redirect` when changed.
		 */
		do_action( 'perflocale/redirected', $plan['emit'], $plan['status'], $context );

		if ( 'perflocale/geo/redirected' === ( $opts['after'][0] ?? '' ) ) {
			$args = array_values( (array) ( $opts['after'][1] ?? [] ) );

			/** @hook perflocale/geo/redirected Fires after a GeoIP redirect. */
			do_action( 'perflocale/geo/redirected', $args[0] ?? '', $args[1] ?? '', $args[2] ?? '' );
		}

		// Cache policy before the seal: both branches run filters (nocache_headers, max_age).
		$max_age = -1;

		if ( $private || self::no_store_required() ) {
			CacheHeaders::require_no_store();
		} elseif ( $plan['status'] === 301 || $plan['status'] === 308 ) {
			$max_age = max( 0, self::max_age() );
		} elseif ( isset( $opts['fb'] ) ) {
			// A temporary missing-translation hop ends when the translation is published: browsers only, briefly.
			$max_age = min( self::FALLBACK_MAX_AGE, max( 0, self::max_age() ) );
		}

		// The seal runs no callback, so no listener leaves a different Location, status or weaker cache header.
		header( 'Location: ' . $plan['emit'], true, $plan['status'] );

		if ( $max_age >= 0 ) {
			self::keep_private( $max_age );
		}
	}

	/**
	 * Make a URL-only permanent hop private: a shared cache keeps none, so renaming a base back cannot loop there.
	 *
	 * Only a queued unqualified no-store without public or s-maxage is kept; anything else becomes private, no longer
	 * than any queued lifetime (no-cache = 0), so a queued private cannot outlive the filter either.
	 *
	 * @param int $max_age Seconds the browser may keep the hop.
	 * @return void
	 */
	private static function keep_private( int $max_age ): void {
		if ( headers_sent() ) {
			return;
		}

		$store  = false;
		$shared = false;

		foreach ( self::queued( 'cache-control' ) as $value ) {
			preg_match_all( '/(?:[^,"]+|"(?:[^"\\\\]|\\\\.)*")+/', $value, $m );

			foreach ( $m[0] as $directive ) {
				$pair = array_map( 'trim', explode( '=', strtolower( trim( $directive ) ), 2 ) );
				$bare = ! isset( $pair[1] );

				// A field-qualified no-cache ("Set-Cookie") still lets a browser reuse the hop unrevalidated.
				if ( $pair[0] === 'public' || $pair[0] === 's-maxage' ) {
					$shared = true;
				} elseif ( $bare && $pair[0] === 'no-store' ) {
					$store = true;
				} elseif ( $bare && $pair[0] === 'no-cache' ) {
					$max_age = 0;
				} elseif ( $pair[0] === 'max-age' && ! $bare ) {
					$max_age = min( $max_age, max( 0, (int) trim( $pair[1], '"' ) ) );
				}
			}
		}

		if ( ! $store || $shared ) {
			foreach ( self::queued( 'expires' ) as $expires ) {
				$at      = strtotime( $expires );
				$max_age = min( $max_age, $at === false ? 0 : max( 0, $at - time() ) );
			}

			header( 'Cache-Control: private, ' . ( $store ? 'no-store' : 'max-age=' . $max_age ) );
			header_remove( 'Expires' );
		}

		// Targeted controls override Cache-Control at a CDN or nginx.
		foreach ( headers_list() as $header ) {
			$name  = trim( (string) strstr( $header, ':', true ) );
			$lower = strtolower( $name );

			if ( $lower === 'surrogate-control' || $lower === 'cdn-cache-control' || str_ends_with( $lower, '-cdn-cache-control' ) ) {
				header( $name . ': no-store' );
			} elseif ( $lower === 'x-accel-expires' ) {
				header( 'X-Accel-Expires: 0' );
			}
		}
	}

	/**
	 * Seconds a URL-only permanent redirect may be cached (here, not in CacheHeaders, so such a hop loads one file).
	 *
	 * @return int
	 */
	private static function max_age(): int {
		/**
		 * Seconds a browser may keep a URL-only permanent PerfLocale redirect (private: no shared cache); 0 revalidates.
		 *
		 * Without it a browser keeps a 301 forever, so renaming a base back to an old value would loop there.
		 *
		 * @hook perflocale/redirect/max_age
		 * @since 1.0.8
		 * @param int $seconds Default 3600.
		 */
		return (int) apply_filters( 'perflocale/redirect/max_age', 3600 );
	}

	/**
	 * Status of a missing-translation hop after `perflocale/fallback/redirect_status`; outside 301/302/307/308 the planned one.
	 *
	 * @param int                                       $status Planned status (302, or 301 for the own-language sibling).
	 * @param array{0: string, 1: string, 2: int, 3: int} $fb   From slug, to slug, from post id, to post id.
	 * @return int
	 */
	private static function fallback_status( int $status, array $fb ): int {
		/**
		 * Filter the HTTP status code used for language-fallback redirects.
		 *
		 * Default 302, so fallbacks stop once the translation is published; 301 for the current
		 * language's own translation. Values outside {301, 302, 307, 308} fall back to the default.
		 *
		 * @hook perflocale/fallback/redirect_status
		 * @param int $status Default 302; 301 for the current language's own translation.
		 * @param string $from_slug Source language slug.
		 * @param string $to_slug Target language slug.
		 * @param int $from_post_id Source post being rendered.
		 * @param int $to_post_id Target post ID (0 if redirecting to the language homepage).
		 */
		$filtered = (int) apply_filters( 'perflocale/fallback/redirect_status', $status, $fb[0], $fb[1], $fb[2], $fb[3] );

		return in_array( $filtered, [ 301, 302, 307, 308 ], true ) ? $filtered : $status;
	}

	/**
	 * Remove a queued `perflocale_lang` Set-Cookie and keep every other cookie.
	 *
	 * @return void
	 */
	private static function forget_language_cookie(): void {
		if ( headers_sent() ) {
			return;
		}

		$keep  = [];
		$found = false;

		foreach ( self::queued( 'set-cookie' ) as $cookie ) {
			if ( str_starts_with( $cookie, 'perflocale_lang=' ) ) {
				$found = true;
			} else {
				$keep[] = $cookie;
			}
		}

		if ( ! $found ) {
			return;
		}

		header_remove( 'Set-Cookie' );

		foreach ( $keep as $cookie ) {
			header( 'Set-Cookie: ' . $cookie, false );
		}
	}

	/**
	 * Withdraw the first-visit default-language cookie from a hop no first-visit rule decided, so the next hop can steer.
	 *
	 * @internal Hooked on wp_redirect by LanguageRouter when it queues that cookie.
	 *
	 * @param mixed $location Location.
	 * @return mixed
	 */
	public static function first_visit_hop( $location ) {
		if ( $location && ! ( class_exists( FirstVisit::class, false ) && FirstVisit::decided() ) ) {
			self::forget_language_cookie();
		}

		return $location;
	}

	/**
	 * Values of a queued response header (lower-case name).
	 *
	 * @param string $name Header name, lower case.
	 * @return array<int, string>
	 */
	private static function queued( string $name ): array {
		$out = [];

		foreach ( headers_list() as $header ) {
			$colon = strpos( $header, ':' );

			if ( $colon !== false && strtolower( trim( substr( $header, 0, $colon ) ) ) === $name ) {
				$out[] = trim( substr( $header, $colon + 1 ) );
			}
		}

		return $out;
	}

	/**
	 * True while PerfLocale's own wp_redirect() runs (read by the foreign Location normalizer).
	 *
	 * @return bool
	 */
	public static function sending(): bool {
		return self::$sending;
	}

	/**
	 * Final `wp_redirect` guard: a Location another callback changed is re-checked in full, or refused.
	 *
	 * @internal Hooked only during PerfLocale's own send.
	 *
	 * @param mixed $location Location after the other callbacks.
	 * @return mixed The accepted Location, or false.
	 */
	public static function guard_location( $location ) {
		$plan = self::$plan;

		if ( $plan === null || $location === $plan['emit'] ) {
			return $location;
		}

		// Another plugin rewrote our target: treat it as visitor-dependent before any return.
		CacheHeaders::require_no_store();

		if ( ! is_string( $location ) || $location === '' ) {
			return false;
		}

		$loc = self::recheck( self::resolve( $location ) );

		if ( $loc === null || self::invalid( $loc['emit'], $loc['abs'] ) !== '' ) {
			return false;
		}

		$plan['emit']    = $loc['emit'];
		$plan['abs']     = $loc['abs'];
		$plan['changed'] = 'wp_redirect';
		self::$plan      = $plan;

		return $loc['emit'];
	}

	/**
	 * Final `wp_redirect_status` guard: keeps the status in the reason's 3xx set, so core's wp_die() is never reached.
	 *
	 * @internal Hooked only during PerfLocale's own send.
	 *
	 * @param mixed $status Status after the other callbacks.
	 * @return int
	 */
	public static function guard_status( $status ): int {
		$plan = self::$plan;

		if ( $plan === null ) {
			return is_numeric( $status ) ? (int) $status : 302;
		}

		$value          = is_numeric( $status ) ? (int) $status : 0;
		$plan['status'] = self::status( $value, $plan['status'], $plan['visitor'] );
		self::$plan     = $plan;

		return $plan['status'];
	}

	/**
	 * Allowed status for a reason class: visitor 302/303/307 (permanent forced to 302), others 301/302/303/307/308.
	 *
	 * @internal Public for the unit tests.
	 *
	 * @param int  $status  Requested status.
	 * @param int  $planned Status the rule planned.
	 * @param bool $visitor Visitor reason.
	 * @return int
	 */
	public static function status( int $status, int $planned, bool $visitor ): int {
		$allowed = $visitor ? [ 302, 303, 307 ] : [ 301, 302, 303, 307, 308 ];

		if ( in_array( $status, $allowed, true ) ) {
			return $status;
		}

		return in_array( $planned, $allowed, true ) ? $planned : 302;
	}

	/**
	 * Step 0: sanitized emitted form (the caller's, relative stays relative) and the absolute validation form.
	 *
	 * @internal Public for the unit tests.
	 *
	 * @param string $target Location as built by a rule or a filter.
	 * @return array{emit: string, abs: string} `abs` is '' for a path-relative target (refused).
	 */
	public static function resolve( string $target ): array {
		$emit = (string) wp_sanitize_redirect( $target );

		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $emit ) === 1 ) {
			return [ 'emit' => $emit, 'abs' => $emit ];
		}

		if ( str_starts_with( $emit, '//' ) ) {
			return [ 'emit' => $emit, 'abs' => self::home_scheme() . ':' . $emit ];
		}

		if ( str_starts_with( $emit, '/' ) ) {
			return [ 'emit' => $emit, 'abs' => self::origin() . $emit ];
		}

		return [ 'emit' => $emit, 'abs' => '' ];
	}

	/**
	 * Comparison key of an absolute URL: path and query (host and port in host modes), no scheme.
	 *
	 * @internal Public for the unit tests.
	 *
	 * @param string $abs       Absolute URL.
	 * @param bool   $with_host Host-routed URL mode.
	 * @param bool   $fold      Lower-case escapes (the refused set); the self check compares their bytes, as the rules do.
	 * @return string
	 */
	public static function norm( string $abs, bool $with_host, bool $fold = true ): string {
		$parts = wp_parse_url( $abs );

		if ( ! is_array( $parts ) ) {
			return $abs;
		}

		$out = (string) ( $parts['path'] ?? '' );
		$out = $out === '' ? '/' : $out;

		if ( isset( $parts['query'] ) && $parts['query'] !== '' ) {
			$out .= '?' . $parts['query'];
		}

		if ( $with_host ) {
			$port = self::port( $parts );
			$out  = strtolower( (string) ( $parts['host'] ?? '' ) ) . ( $port !== '' ? ':' . $port : '' ) . $out;
		}

		return $fold ? (string) preg_replace_callback( '/%[0-9a-f]{2}/i', static fn( array $m ): string => strtolower( $m[0] ), $out ) : $out;
	}

	/**
	 * Why the exact Location fails validation (5.4), or '' when it passes.
	 *
	 * @internal Public for the unit tests.
	 *
	 * @param string $emit Location as emitted.
	 * @param string $abs  Its absolute form.
	 * @return string Failed check, or ''.
	 */
	public static function invalid( string $emit, string $abs ): string {
		if ( $abs === '' ) {
			return 'relative';
		}

		if ( strlen( $emit ) > self::MAX_LENGTH ) {
			return 'length';
		}

		if ( preg_match( '/[\x00-\x20\x7F\\\\]/', $emit . $abs ) === 1 ) {
			return 'bytes';
		}

		if ( preg_match( '/%(?:[01][0-9a-f]|7f|5c)/i', $abs ) === 1 ) {
			return 'encoded_control';
		}

		$parts  = wp_parse_url( $abs );
		$parts  = is_array( $parts ) ? $parts : [];
		$scheme = strtolower( $parts['scheme'] ?? '' );
		$host   = $parts['host'] ?? '';

		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || $host === '' ) {
			return 'parse';
		}

		$path = $parts['path'] ?? '';

		if ( stripos( $path, '%2f' ) !== false ) {
			return 'encoded_slash';
		}

		foreach ( explode( '/', $path ) as $segment ) {
			$segment = str_ireplace( '%2e', '.', $segment );

			if ( $segment === '.' || $segment === '..' ) {
				return 'dot_segment';
			}
		}

		$home = self::home();

		// An https home never goes to http; an http home may go to https (WooCommerce force-SSL).
		$home_scheme = strtolower( $home['scheme'] ?? 'http' );

		if ( $scheme !== $home_scheme && ! ( $home_scheme === 'http' && $scheme === 'https' ) ) {
			return 'scheme';
		}

		$ports = self::origins()[ LanguageRouter::normalize_host( $host ) ] ?? null;

		if ( $ports === null || ! isset( $ports[ self::port( $parts ) ] ) ) {
			return 'origin';
		}

		// Segment-bounded: blog /site2 never reaches /site20.
		$home_path = rtrim( $home['path'] ?? '', '/' );

		if ( $home_path !== '' && $path !== $home_path && ! str_starts_with( $path, $home_path . '/' ) ) {
			return 'home_path';
		}

		if ( wp_sanitize_redirect( $emit ) !== $emit || wp_sanitize_redirect( $abs ) !== $abs ) {
			return 'sanitize';
		}

		return '';
	}

	/**
	 * Whether the request carries a core or WooCommerce action or credential (5.7): `$_GET` and the path only.
	 *
	 * @internal Public for the unit tests.
	 *
	 * @param array<array-key, mixed> $get  Query args (unslashed).
	 * @param string                  $path Request path.
	 * @return bool
	 */
	public static function is_action_request( array $get, string $path ): bool {
		$keys = self::ACTION_ARGS;

		/**
		 * Query keys that block every PerfLocale redirect when present; core's keys cannot be removed.
		 *
		 * @hook perflocale/redirect/action_args
		 * @since 1.0.8
		 * @param array $keys Query keys (strings).
		 */
		foreach ( (array) apply_filters( 'perflocale/redirect/action_args', self::ACTION_ARGS ) as $extra ) {
			if ( is_string( $extra ) ) {
				$keys[] = $extra;
			}
		}

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $get ) ) {
				return true;
			}
		}

		$key = $get['key'] ?? null;

		if ( ( isset( $get['preview'] ) && isset( $get['preview_id'] ) )
			|| ( isset( $get['unapproved'] ) && isset( $get['moderation-hash'] ) )
			|| ( $key !== null && ( isset( $get['id'] ) || isset( $get['login'] ) || ( is_string( $key ) && str_starts_with( $key, 'wc_order_' ) ) ) )
		) {
			return true;
		}

		if ( ! class_exists( 'WooCommerce', false ) ) {
			return false;
		}

		$segments = array_values( array_filter( explode( '/', $path ), static fn( string $segment ): bool => $segment !== '' ) );
		$logout   = self::option( 'woocommerce_logout_endpoint', 'customer-logout' );

		if ( $logout !== '' && ( end( $segments ) === $logout || array_key_exists( $logout, $get ) ) ) {
			return true;
		}

		// Order endpoints: without `key` only with an order id after them, so a page slug like `order-received` still hops.
		foreach ( [ 'woocommerce_checkout_pay_endpoint' => 'order-pay', 'woocommerce_checkout_order_received_endpoint' => 'order-received' ] as $option => $fallback ) {
			$endpoint = self::option( $option, $fallback );
			$at       = $endpoint !== '' ? array_search( $endpoint, $segments, true ) : false;

			if ( ( $endpoint !== '' && array_key_exists( $endpoint, $get ) ) || ( is_int( $at ) && ( $key !== null || 1 === preg_match( '/\A[0-9]+\z/', $segments[ $at + 1 ] ?? '' ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Steps 2-4: refused-set and self checks, base normalization, the checks again on each normalized value.
	 *
	 * @param array{emit: string, abs: string} $loc Resolved target.
	 * @return array{emit: string, abs: string}|null Null when declined, also when normalization never settles.
	 */
	private static function recheck( array $loc ): ?array {
		$hosts = self::host_mode();
		$self  = self::norm( self::request_abs(), $hosts, false );

		for ( $pass = 0; $pass < 3; $pass++ ) {
			if ( isset( self::$refused[ self::norm( $loc['abs'], $hosts ) ] ) || self::norm( $loc['abs'], $hosts, false ) === $self ) {
				return null;
			}

			$canonical = $loc['abs'] !== '' ? self::base_normalize( $loc['abs'] ) : '';

			if ( $canonical === '' || $canonical === $loc['abs'] ) {
				return $loc;
			}

			$loc = self::with_abs( $loc, $canonical );
		}

		return null;
	}

	/**
	 * Step 4: replace a base alias span with its canonical value (map active only).
	 *
	 * @param string $abs Absolute target.
	 * @return string
	 */
	private static function base_normalize( string $abs ): string {
		// Loaded only where a map exists; the sender never loads it.
		if ( ! class_exists( Runtime::class, false ) ) {
			return $abs;
		}

		$out = Runtime::canonicalize_url( $abs );

		return $out !== '' ? (string) wp_sanitize_redirect( $out ) : $abs;
	}

	/**
	 * Apply a change of the absolute form to both forms; the emitted form turns absolute only when its origin changes.
	 *
	 * @internal Public for the unit tests.
	 *
	 * @param array{emit: string, abs: string} $loc Current forms.
	 * @param string                           $abs New absolute form.
	 * @return array{emit: string, abs: string}
	 */
	public static function with_abs( array $loc, string $abs ): array {
		if ( $abs === $loc['abs'] ) {
			return $loc;
		}

		$relative = str_starts_with( $loc['emit'], '/' ) && ! str_starts_with( $loc['emit'], '//' );
		$new      = wp_parse_url( $abs );

		if ( $relative && is_array( $new ) && self::origin_of( $abs ) === self::origin_of( $loc['abs'] ) ) {
			$emit = (string) ( $new['path'] ?? '/' );
			$emit = ( $emit === '' ? '/' : $emit )
				. ( isset( $new['query'] ) ? '?' . $new['query'] : '' )
				. ( isset( $new['fragment'] ) ? '#' . $new['fragment'] : '' );

			return [ 'emit' => $emit, 'abs' => $abs ];
		}

		return [ 'emit' => $abs, 'abs' => $abs ];
	}

	/**
	 * Lower-cased scheme, host and explicit non-default port of an absolute URL.
	 *
	 * @param string $abs Absolute URL.
	 * @return string
	 */
	private static function origin_of( string $abs ): string {
		$parts = wp_parse_url( $abs );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		return strtolower( (string) ( $parts['scheme'] ?? '' ) . '://' . (string) ( $parts['host'] ?? '' ) ) . ':' . self::port( $parts );
	}

	/**
	 * Explicit port, '' when absent or the scheme's default.
	 *
	 * @param array{scheme?: string, port?: int} $parts wp_parse_url() result.
	 * @return string
	 */
	private static function port( array $parts ): string {
		if ( ! isset( $parts['port'] ) ) {
			return '';
		}

		$default = strtolower( $parts['scheme'] ?? '' ) === 'https' ? 443 : 80;

		return $parts['port'] === $default ? '' : (string) $parts['port'];
	}

	/**
	 * The raw `home` option parsed (never the filtered home_url(), which may carry a prefix).
	 *
	 * @return array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string}
	 */
	private static function home(): array {
		$blog = get_current_blog_id();

		if ( ! isset( self::$home[ $blog ] ) ) {
			self::watch();
			$parts               = wp_parse_url( self::option( 'home', '' ) );
			self::$home[ $blog ] = is_array( $parts ) ? $parts : [];
		}

		return self::$home[ $blog ];
	}

	/**
	 * Drop the memoized home and origins when home changes or the blog switches in this request (redirect checks must
	 * see the current scheme, host and path). Idempotent: one callback per hook.
	 *
	 * @return void
	 */
	private static function watch(): void {
		foreach ( [ 'add_option_home', 'update_option_home', 'delete_option_home', 'switch_blog' ] as $hook ) {
			add_action( $hook, [ self::class, 'forget' ] );
		}
	}

	/**
	 * Drop the memoized home and origins of every blog.
	 *
	 * @internal Hooked by watch().
	 *
	 * @return void
	 */
	public static function forget(): void {
		self::$home    = [];
		self::$origins = [];
	}

	/**
	 * A string option, or the fallback.
	 *
	 * @param string $name     Option name.
	 * @param string $fallback Value when the option is not a string.
	 * @return string
	 */
	private static function option( string $name, string $fallback ): string {
		$value = get_option( $name, $fallback );

		return is_string( $value ) ? $value : $fallback;
	}

	/**
	 * Allowed hosts of this blog with their ports: home, home_url(), and in host modes what UrlConverter emits per active language.
	 *
	 * @return array<string, array<int|string, true>>
	 */
	private static function origins(): array {
		$blog = get_current_blog_id();

		if ( isset( self::$origins[ $blog ] ) ) {
			return self::$origins[ $blog ];
		}

		self::watch();
		$home      = self::option( 'home', '' );
		$urls      = [ $home, home_url( '/' ) ];
		$router    = self::router();
		$plugin    = Plugin::get_instance();
		$converter = $plugin->has( 'url_converter' ) ? $plugin->get( 'url_converter' ) : null;

		if ( $router !== null && $converter instanceof UrlConverter && self::host_mode() ) {
			foreach ( $router->get_active_languages() as $language ) {
				$slug = self::slug_of( $language );

				if ( $slug !== '' ) {
					$urls[] = $converter->convert( rtrim( $home, '/' ) . '/', $slug );
				}
			}
		}

		self::$origins[ $blog ] = self::origin_map( $urls );

		return self::$origins[ $blog ];
	}

	/**
	 * Host => allowed ports of absolute URLs ('' is the scheme's default port).
	 *
	 * @internal Public for the unit tests.
	 *
	 * @param array<int, mixed> $urls Absolute URLs.
	 * @return array<string, array<int|string, true>>
	 */
	public static function origin_map( array $urls ): array {
		$out = [];

		foreach ( $urls as $url ) {
			$parts = is_string( $url ) ? wp_parse_url( $url ) : false;
			$host  = is_array( $parts ) ? LanguageRouter::normalize_host( (string) ( $parts['host'] ?? '' ) ) : '';

			if ( is_array( $parts ) && $host !== '' ) {
				$out[ $host ][ self::port( $parts ) ] = true;
			}
		}

		return $out;
	}

	/**
	 * Origin a root-relative target resolves against: home's, or the request's language host in host modes.
	 *
	 * @return string
	 */
	private static function origin(): string {
		$home   = self::home();
		$scheme = strtolower( $home['scheme'] ?? 'http' );
		$origin = $scheme . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );

		if ( ! self::host_mode() ) {
			return $origin;
		}

		$request = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		$parsed  = $request !== '' ? wp_parse_url( $scheme . '://' . $request ) : false;

		// Host and port only: a Host header with a path, query, fragment or user info is never an origin.
		if ( ! is_array( $parsed ) || ! isset( $parsed['host'] ) || array_diff_key( $parsed, [ 'scheme' => 1, 'host' => 1, 'port' => 1 ] ) !== [] ) {
			return $origin;
		}

		if ( ! isset( self::origins()[ LanguageRouter::normalize_host( $parsed['host'] ) ][ self::port( $parsed ) ] ) ) {
			return $origin;
		}

		return $scheme . '://' . $parsed['host'] . ( isset( $parsed['port'] ) ? ':' . $parsed['port'] : '' );
	}

	/**
	 * Scheme of the home URL.
	 *
	 * @return string
	 */
	private static function home_scheme(): string {
		return strtolower( self::home()['scheme'] ?? 'http' );
	}

	/**
	 * The current request resolved and sanitized the way a target is.
	 *
	 * @return string
	 */
	private static function request_abs(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_sanitize_redirect() in resolve().
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

		return self::resolve( $uri === '' ? '/' : $uri )['abs'];
	}

	/**
	 * Whether the URL mode routes languages by host.
	 *
	 * @return bool
	 */
	private static function host_mode(): bool {
		$settings = self::settings();

		return $settings !== null && in_array( $settings->get_url_mode(), [ 'domain', 'subdomain' ], true );
	}

	/**
	 * Front-end request: not admin, AJAX, cron, REST, XML-RPC or CLI.
	 *
	 * @return bool
	 */
	private static function front_end(): bool {
		return ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			&& ! ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * GET or HEAD request.
	 *
	 * @return bool
	 */
	private static function get_or_head(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		return $method === 'GET' || $method === 'HEAD';
	}

	/**
	 * Step 5: the current request carries an action or credential.
	 *
	 * @return bool
	 */
	private static function action_request(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_sanitize_redirect() below.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = (string) wp_sanitize_redirect( explode( '#', explode( '?', $uri, 2 )[0], 2 )[0] );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only classification.
		return self::is_action_request( wp_unslash( $_GET ), $path );
	}

	/**
	 * Step 5: the request path is an excluded path (no PerfLocale redirect of any kind).
	 *
	 * @return bool
	 */
	private static function excluded_request(): bool {
		$router = self::router();

		return $router !== null && $router->is_excluded_request_path();
	}

	/**
	 * #2 reads the cookie; #3 sends only because no cookie, browser or edge signal picked another language.
	 *
	 * @param string $reason Reason code.
	 * @return bool
	 */
	private static function cookie_dependent( string $reason ): bool {
		if ( $reason === 'sentinel_clean' ) {
			return true;
		}

		$settings = $reason === 'default_to_prefix' ? self::settings() : null;

		return $settings !== null && array_intersect( [ 'cookie', 'browser', 'edge_hint' ], $settings->get_detection_order() ) !== [];
	}

	/**
	 * Whether this response already must be no-store.
	 *
	 * @return bool
	 */
	private static function no_store_required(): bool {
		return class_exists( CacheHeaders::class, false ) && CacheHeaders::required();
	}

	/**
	 * Record a refused target, write one debug line and return REFUSED.
	 *
	 * @param string $abs    Absolute target.
	 * @param string $reason Reason code.
	 * @param string $why    Failed check.
	 * @param string $first  The planned hop a filter swapped for $abs: held too.
	 * @return int
	 */
	private static function refuse( string $abs, string $reason, string $why, string $first = '' ): int {
		// No absolute form: recording norm('') would block a later hop to "/".
		if ( $abs !== '' ) {
			self::$refused[ self::norm( $abs, self::host_mode() ) ] = true;
			self::hold( $abs );
		}

		if ( $first !== '' && $first !== $abs ) {
			self::hold( $first );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( 'PerfLocale: redirect %s refused (%s)', $reason, $why ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return self::REFUSED;
	}

	/**
	 * Keep core's redirect_canonical from sending a declined hop: it rebuilds the same URL through the language filters.
	 *
	 * @param string $abs Absolute target.
	 * @return void
	 */
	private static function hold( string $abs ): void {
		add_filter( 'redirect_canonical', [ self::class, 'drop_canonical' ], PHP_INT_MAX );

		self::$declined[ self::path_key( $abs ) ] = true;
	}

	/**
	 * False for a canonical target on a declined path, any scheme, query or trailing slash.
	 *
	 * @internal Hooked by hold().
	 *
	 * @param mixed $url Canonical target.
	 * @return mixed
	 */
	public static function drop_canonical( $url ) {
		return is_string( $url ) && isset( self::$declined[ self::path_key( $url ) ] ) ? false : $url;
	}

	/**
	 * Comparison key without query, fragment or trailing slash (core's canonical adds the permalink's slash).
	 *
	 * @param string $abs Absolute URL.
	 * @return string
	 */
	private static function path_key( string $abs ): string {
		return self::norm( untrailingslashit( explode( '?', explode( '#', $abs, 2 )[0], 2 )[0] ), self::host_mode() );
	}

	/**
	 * Post-send check: exactly one Location equal to the accepted one, and the accepted status.
	 *
	 * headers_list() and http_response_code() are empty under CLI, so the check runs on real requests only.
	 *
	 * @param string $emit   Accepted Location.
	 * @param int    $status Accepted status.
	 * @return bool
	 */
	private static function post_send_ok( string $emit, int $status ): bool {
		if ( PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' ) {
			return true;
		}

		$sent = self::queued( 'location' );

		return count( $sent ) === 1 && $sent[0] === wp_sanitize_redirect( $emit ) && http_response_code() === $status;
	}

	/**
	 * Arm the final guards: removed and re-added, so they are the last PHP_INT_MAX callbacks.
	 *
	 * @return void
	 */
	private static function arm(): void {
		self::disarm( null );
		add_filter( 'wp_redirect', [ self::class, 'guard_location' ], PHP_INT_MAX, 1 );
		add_filter( 'wp_redirect_status', [ self::class, 'guard_status' ], PHP_INT_MAX, 1 );
	}

	/**
	 * Remove the final guards and the per-send host allowance.
	 *
	 * @param \Closure|null $host allowed_redirect_hosts callback of this send.
	 * @return void
	 */
	private static function disarm( ?\Closure $host ): void {
		remove_filter( 'wp_redirect', [ self::class, 'guard_location' ], PHP_INT_MAX );
		remove_filter( 'wp_redirect_status', [ self::class, 'guard_status' ], PHP_INT_MAX );

		if ( $host !== null ) {
			remove_filter( 'allowed_redirect_hosts', $host );
		}
	}

	/**
	 * Step 9: allow a language host for this send only (third-party wp_redirect callbacks may validate it).
	 *
	 * @param string $abs Absolute target.
	 * @return \Closure|null The callback to remove after the send.
	 */
	private static function allow_host( string $abs ): ?\Closure {
		$host = (string) wp_parse_url( $abs, PHP_URL_HOST );

		if ( $host === '' || ! self::host_mode() ) {
			return null;
		}

		$callback = static function ( $hosts ) use ( $host ) {
			return is_array( $hosts ) ? array_merge( $hosts, [ $host ] ) : $hosts;
		};

		add_filter( 'allowed_redirect_hosts', $callback );

		return $callback;
	}

	/**
	 * Context of the perflocale/redirect filter and the perflocale/redirected action.
	 *
	 * @param string $reason  Reason code.
	 * @param string $abs     Absolute target.
	 * @param bool   $visitor Visitor reason.
	 * @return array<string, mixed>
	 */
	private static function context( string $reason, string $abs, bool $visitor ): array {
		$router   = self::router();
		$settings = self::settings();
		$object   = isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query ? get_queried_object() : null;
		$request  = self::request_abs();
		$span     = $reason === 'base_alias' && class_exists( Runtime::class, false ) ? Runtime::span() : null;
		$group    = $span['grp'] ?? '';

		return array_merge(
			[
				'reason'           => $reason,
				'rule'             => self::RULES[ $reason ] ?? '',
				'source_path'      => (string) wp_parse_url( $request, PHP_URL_PATH ),
				'from_language'    => self::language_of( $request ),
				'request_language' => $router !== null ? $router->get_current_slug() : '',
				'detection'        => LanguageRouter::detection(),
				'visitor'          => $visitor,
				'object_type'      => $object instanceof \WP_Post ? 'post' : ( $object instanceof \WP_Term ? 'term' : ( $object instanceof \WP_Post_Type ? 'post_type' : ( $object instanceof \WP_User ? 'user' : '' ) ) ),
				'object_id'        => $object instanceof \WP_Post ? (int) $object->ID : ( $object instanceof \WP_Term ? (int) $object->term_id : ( $object instanceof \WP_User ? (int) $object->ID : 0 ) ),
				'post_type'        => $object instanceof \WP_Post ? $object->post_type : ( $object instanceof \WP_Post_Type ? $object->name : '' ),
				'taxonomy'         => $object instanceof \WP_Term ? $object->taxonomy : '',
				'route'            => self::route(),
				'url_mode'         => $settings !== null ? $settings->get_url_mode() : '',
				'hide_default'     => $settings !== null && $settings->hide_default_prefix(),
				'key'              => $span['key'] ?? '',
				'alias_kind'       => $span['kind'] ?? '',
				'addon'            => $group !== '' && $group !== 'core' ? $group : '',
				'blog_id'          => get_current_blog_id(),
			],
			self::target_facts( $abs )
		);
	}

	/**
	 * Context keys that describe the target: path, query key names, language.
	 *
	 * @param string $abs Absolute target.
	 * @return array{target_path: string, query_keys: array<int, string>, language: string}
	 */
	private static function target_facts( string $abs ): array {
		$args  = [];
		$query = (string) wp_parse_url( $abs, PHP_URL_QUERY );

		if ( $query !== '' ) {
			wp_parse_str( $query, $args );
		}

		return [
			'target_path' => (string) wp_parse_url( $abs, PHP_URL_PATH ),
			'query_keys'  => array_map( 'strval', array_keys( $args ) ),
			'language'    => self::language_of( $abs ),
		];
	}

	/**
	 * Language an absolute URL marks under the URL mode, '' when it marks none.
	 *
	 * @param string $abs Absolute URL.
	 * @return string
	 */
	private static function language_of( string $abs ): string {
		$router   = self::router();
		$settings = self::settings();
		$parts    = wp_parse_url( $abs );

		if ( $router === null || $settings === null || ! is_array( $parts ) ) {
			return '';
		}

		$default = self::slug_of( $router->get_default_language() );
		$mode    = $settings->get_url_mode();

		if ( $mode === 'query' ) {
			$args = [];
			wp_parse_str( (string) ( $parts['query'] ?? '' ), $args );
			$value = $args[ UrlConverter::query_var() ] ?? '';

			return is_string( $value ) && $value !== '' ? (string) $router->slug_for_path_prefix( $value ) : $default;
		}

		if ( $mode === 'subdirectory' ) {
			$home_path = rtrim( self::home()['path'] ?? '', '/' );
			$path      = $parts['path'] ?? '';
			$rest      = $home_path !== '' && str_starts_with( $path, $home_path ) ? substr( $path, strlen( $home_path ) ) : $path;
			$first     = explode( '/', trim( $rest, '/' ) )[0];
			$slug      = $router->slug_for_path_prefix( $first );

			return $slug ?? ( $settings->hide_default_prefix() ? $default : '' );
		}

		$host = LanguageRouter::normalize_host( $parts['host'] ?? '' );

		/** @var array<string, mixed> $domains The stored option: a value is not always a string. */
		$domains = $settings->get_language_domains();

		foreach ( $router->get_active_languages() as $language ) {
			$slug   = self::slug_of( $language );
			$value  = $domains[ $slug ] ?? '';
			$domain = $mode === 'domain'
				? LanguageRouter::normalize_host( (string) wp_parse_url( '//' . (string) preg_replace( '#^[a-z]+://#i', '', is_string( $value ) ? $value : '' ), PHP_URL_HOST ) )
				: $slug . '.' . LanguageRouter::normalize_host( self::home()['host'] ?? '' );

			if ( $slug !== '' && $slug !== $default && $domain === $host ) {
				return $slug;
			}
		}

		return $default;
	}

	/**
	 * The value when it is an array, else [] (a filter callback may return anything).
	 *
	 * @param mixed $value Filtered value.
	 * @return array<array-key, mixed>
	 */
	private static function as_array( $value ): array {
		return is_array( $value ) ? $value : [];
	}

	/**
	 * Slug of a language row, '' when it has none.
	 *
	 * @param object|null $language Language row.
	 * @return string
	 */
	private static function slug_of( ?object $language ): string {
		return $language !== null && isset( $language->slug ) && is_string( $language->slug ) ? $language->slug : '';
	}

	/**
	 * Route class of the main query.
	 *
	 * @return string
	 */
	private static function route(): string {
		if ( ! isset( $GLOBALS['wp_query'] ) || ! $GLOBALS['wp_query'] instanceof \WP_Query ) {
			return 'other';
		}

		if ( is_front_page() ) {
			return 'front';
		}

		if ( is_home() ) {
			return 'home';
		}

		if ( is_singular() ) {
			return 'singular';
		}

		if ( is_tax() || is_category() || is_tag() ) {
			return 'term';
		}

		if ( is_search() ) {
			return 'search';
		}

		if ( is_404() ) {
			return '404';
		}

		return is_archive() ? 'archive' : 'other';
	}

	/**
	 * The router service, or null before the container has it.
	 *
	 * @return LanguageRouter|null
	 */
	private static function router(): ?LanguageRouter {
		$plugin = Plugin::get_instance();
		$router = $plugin->has( 'router' ) ? $plugin->get( 'router' ) : null;

		return $router instanceof LanguageRouter ? $router : null;
	}

	/**
	 * The settings service, or null before the container has it.
	 *
	 * @return Settings|null
	 */
	private static function settings(): ?Settings {
		$plugin   = Plugin::get_instance();
		$settings = $plugin->has( 'settings' ) ? $plugin->get( 'settings' ) : null;

		return $settings instanceof Settings ? $settings : null;
	}
}
